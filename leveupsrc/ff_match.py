"""
Match session: a layered rewrite of the follow bot.

The previous version kept every concern in one place - identity, roster, our
position, the target, the clock, the pacing and the socket all lived in two
overlapping classes that two threads mutated directly. That is what produced
the recurring freezes: a value half-updated by one thread while the other read
it, and a single exception anywhere killing the loop that drove everything.

Four layers, each with one job:

    MatchState       the only source of truth. Every field is guarded by one
                     lock and only ever read through a snapshot, so no consumer
                     can see a half-written state.

    MovementPlanner  pure logic. Given a snapshot it returns the next position.
                     No sockets, no clock, no globals - which means it can be
                     tested exhaustively without a server.

    PacketRouter     a table of command -> handler. Adding a message is one
                     entry; a handler that throws cannot affect any other.

    MatchSession     owns the socket and the lifecycle. Threads are isolated:
                     if the sender dies the receiver still runs, and vice
                     versa, and both restart their own loop.

Wire-level details (packet layout, encryption, field encodings) stay in
ff_udp - this module is about behaviour, not bytes.
"""

import math
import socket
import threading
import time

import ff_udp

try:
    import match_api
except ImportError:                    # the lobby calls are optional
    match_api = None

try:
    import config
except ImportError:                    # keep working without a config file
    class config(object):
        Chat_CommandUdp = True
        AutoSafeZone = True
        AutoXp = True


# ---------------------------------------------------------------------------
# Constants measured from the real client's own traffic
# ---------------------------------------------------------------------------

SEND_HZ = 10.0                 # the client sends 10 state updates per second
MOVE_INTERVAL = 1.0 / SEND_HZ
WALK_SPEED = 5150.0            # units/second, median of real player movement
STEP_PER_TICK = WALK_SPEED / SEND_HZ
SERVER_TICK_HZ = 30.0          # Cmd 2000 advances 30 ticks per second

CMD_PLAYER_JOIN = 101
CMD_MATCH_END = 103            # S2C_RUDP_MatchEnd_Res
CMD_DEAD = 107                 # RUDP_DEAD - PlayerID first
CMD_SAFE_ZONE = 117
CMD_KNOCK_DOWN = 139           # RUDP_KNOCK_DOWN
CMD_KNOCK_REVIVE = 140         # S2C: someone finished a revive
CMD_START_RESCUE = 142         # C2S: TargetPlayerID + StartRescureTime
CMD_STOP_RESCUE = 143          # C2S: TargetPlayerID
CMD_PENDING_REVIVE = 256       # entered the downed state
CMD_REVIVE = 393               # RUDP_NOTIFYREVIVE - back on our feet
CMD_SHOW_MATCH_RESULT = 407    # C2S_RUDP_ShowMatchResult_Req - empty body
CMD_QUICK_CHAT = 551
CMD_GRI_SYNC = 1901            # match phase and round number
CMD_SERVER_TIME = 2000
CMD_MY_STATE = 2001            # carries ONLY us
CMD_OTHER_STATES = 2012        # carries only the OTHER players

# EPlayerPhysXPose. A downed teammate reports this pose in Cmd 2012, and it
# appeared 127 times in a captured match, so it is how the bot spots someone
# who needs picking up.
POSE_KNOCKDOWN = 8

# How close to stand before starting a revive, and how long to wait for it.
# A captured revive ran KNOCK_DOWN at t=45.5, C2S_142 at t=46.9 and the
# server's Cmd 140 at t=49.9 - about three seconds of holding, ended by the
# server rather than by the client. The range is generous because the server
# decides what is close enough, and its refusal reasons are TargetSelfRescure
# and UseRescureZone - distance is not one of them.
REVIVE_RANGE = 3000.0
# Abandoned after this if neither Cmd 140 nor a refusal ever arrives.
REVIVE_TIMEOUT = 12.0
# The request is repeated on this interval until the server answers. UDP drops
# packets and a silent loss here is indistinguishable from a refusal.
REVIVE_RETRY = 1.5

# Phases inside a Clash Squad round, decoded from Cmd 1901. The cycle is
# exact across every round of a captured match: a 24-second buy period, then
# combat, then the round settles.
PHASE_PREPARE = 1
PHASE_FIGHT = 3
PHASE_ROUND_END = 5
PHASE_NAMES = {1: "PREPARE", 2: "phase2", 3: "FIGHT", 4: "phase4",
               5: "ROUND_END"}

# Seconds the real client waits between MatchEnd and asking for the result.
# Measured from a full capture: S2C_103 at t=313.585, C2S_407 at t=320.037.
# The gap is filled by the server's own end-of-match traffic - pickup effects,
# zone updates - and asking before those arrive is not what the client does.
MATCH_RESULT_DELAY = 6.45

# How long to keep the session alive after asking for the result. The same
# capture has the client still exchanging state, acks and pings until t=326.7,
# which is 6.7s past the request, and then simply going quiet. It never sends
# a quit command - there is no C2S_191 or C2S_192 anywhere in the capture, and
# no other close message either. It stops talking and lets the socket go.
POST_RESULT_LINGER = 6.7

# Seconds to wait for Cmd 101 before deciding the join was refused. The real
# client has every player in the roster within about four seconds of sending
# 448, so twice that is generous.
JOIN_TIMEOUT = 8.0

FOLLOW_TRIGGERS = (1, 2)       # "follow me" and "help me"
CHAT_NAMES = {1: "follow me", 2: "help me"}

POSE_STANDING = 0
POSE_DASHING = 2

# The zone radius arrives in a coarser unit than positions. Measured against a
# capture: a stated radius of 67 with players sitting 11k-42k units from the
# centre, so the circle has to be around 67000 for them to be inside it.
ZONE_RADIUS_SCALE = 1000.0
# Start running for the middle once the caller is this far out along the
# radius; three quarters leaves room to cross before the ring closes.
ZONE_DANGER_FRACTION = 0.75

# The server corrects a client outright on revive, knockback or a vehicle. Our
# own stepping is authoritative between updates, but if the gap ever grows past
# this the correction is real and we must accept it - without that check the
# bot walks around in an imaginary position and every move it sends is refused.
RESYNC_DIST = 120000.0

# Collision detection. A sniff of the bot beside the real client is stark: the
# client's commanded position and the server's echo of it differ by a median of
# 190 units, the bot's by 4797 and at worst 56472. That gap is the server
# refusing a move - almost always a wall. Past BLOCK_DIST for BLOCK_TICKS in a
# row we treat ourselves as blocked, because walking a straight line into
# geometry is exactly how the bot used to wedge itself permanently: it kept
# advancing an imaginary position through the wall while the server held it
# outside, and the huge RESYNC_DIST meant the two never reconciled.
BLOCK_DIST = 1500.0
BLOCK_TICKS = 5
# How far to swing the heading when sliding along an obstacle, and how long to
# commit to one side before trying the other.
SLIDE_ANGLE = 65.0
SLIDE_HOLD = 1.5


class Snapshot(object):
    """An immutable copy of the state, handed to the planner.

    Passing a snapshot instead of the live object is what removes the whole
    class of races the old code suffered from: the planner cannot observe a
    field changing underneath it mid-calculation.
    """

    __slots__ = ("my_id", "my_short", "my_pos", "target_short", "target_pos",
                 "target_vel", "target_seen_at", "target_yrot", "target_aim_y",
                 "target_aim_xz", "target_state", "target_pose", "server_tick",
                 "tick_local", "tick_rate", "zone_center", "zone_radius",
                 "server_pos", "blocked_ticks", "phase", "round_no", "alive",
                 "downed", "rescuing", "rescue_started", "joined_at")

    def __init__(self, **kw):
        for k in self.__slots__:
            setattr(self, k, kw.get(k))


class MatchState(object):
    """Everything the session knows, behind one lock.

    Writers are the receive thread; the reader is the send thread. All reads go
    through snapshot(), so a consumer always sees one coherent moment rather
    than a mix of old and new fields.
    """

    def __init__(self):
        self._lock = threading.Lock()
        self.my_id = None            # full PlayerID, e.g. 0x02000006
        self.my_short = None         # low byte of it
        self.my_pos = None           # our position, as we last reported it
        self.roster = {}             # short id -> (full id, name)
        self.target_short = None
        self.target_pos = None
        self.target_vel = (0.0, 0.0)
        self.target_seen_at = None
        self.target_yrot = 0
        self.target_aim_y = 0
        self.target_aim_xz = 0
        self.target_state = 0
        self.target_pose = 0
        self.server_tick = None      # ServerGameTickCount from Cmd 2000
        self.tick_local = None       # time.time() when it arrived
        self.tick_rate = SERVER_TICK_HZ
        self.zone_center = None      # safe-zone centre, from Cmd 117
        self.zone_radius = 0.0       # its radius, already scaled
        self.server_pos = None       # where the server says we actually are
        self.blocked_ticks = 0       # consecutive updates it refused to move us
        self.phase = None            # current round phase, from Cmd 1901
        self.round_no = None         # which round we are in
        self.alive = True            # false while dead or downed
        self.downed = {}             # short id -> position, teammates only
        self.rescuing = None         # full PlayerID we are reviving
        self.rescue_started = 0.0
        self.joined_at = None        # set on the first roster packet

    def teammates(self):
        """Short ids on our team, excluding ourselves.

        The team is the top byte of the full PlayerID - our own capture had the
        bot as 0x01000001 alongside 0x01000006 and 0x01000007, with the enemy
        team all 0x02xxxxxx. Cmd 2012 only carries short ids, so the roster
        from Cmd 101 is what makes the distinction possible at all.
        """
        with self._lock:
            if self.my_id is None:
                return set()
            my_team = (self.my_id >> 24) & 0xFF
            return {short for short, (full, _) in self.roster.items()
                    if (full >> 24) & 0xFF == my_team and short != self.my_short}

    def full_id_of(self, short_id):
        with self._lock:
            entry = self.roster.get(short_id)
            return entry[0] if entry else None

    def set_downed(self, downed):
        with self._lock:
            self.downed = downed

    def mark_down(self, short_id, pos=None):
        """Record a teammate as down from a knockdown notification.

        Cmd 139 names who went down but not where, so the position is left as
        None until Cmd 2012 fills it in. The planner treats a None position as
        'walk to the caller instead', which is the right fallback - it keeps
        the bot moving rather than standing still waiting for coordinates.
        """
        with self._lock:
            if pos is not None or short_id not in self.downed:
                self.downed[short_id] = pos

    def clear_down(self, short_id):
        with self._lock:
            self.downed.pop(short_id, None)

    def begin_rescue(self, full_id, now):
        with self._lock:
            if self.rescuing is not None:
                return False
            self.rescuing = full_id
            self.rescue_started = now
            return True

    def end_rescue(self, full_id=None):
        with self._lock:
            if full_id is not None and self.rescuing != full_id:
                return None
            done = self.rescuing
            self.rescuing = None
            self.rescue_started = 0.0
            return done

    def snapshot(self):
        with self._lock:
            return Snapshot(
                my_id=self.my_id, my_short=self.my_short, my_pos=self.my_pos,
                target_short=self.target_short, target_pos=self.target_pos,
                target_vel=self.target_vel, target_seen_at=self.target_seen_at,
                target_yrot=self.target_yrot, target_aim_y=self.target_aim_y,
                target_aim_xz=self.target_aim_xz,
                target_state=self.target_state, target_pose=self.target_pose,
                server_tick=self.server_tick, tick_local=self.tick_local,
                tick_rate=self.tick_rate, zone_center=self.zone_center,
                zone_radius=self.zone_radius, server_pos=self.server_pos,
                blocked_ticks=self.blocked_ticks, phase=self.phase,
                round_no=self.round_no, alive=self.alive,
                downed=dict(self.downed), rescuing=self.rescuing,
                rescue_started=self.rescue_started,
                joined_at=self.joined_at)

    def set_alive(self, alive):
        """Mark us up or down. A downed or dead player has no business sending
        movement, and doing so is the sort of thing a server notices."""
        with self._lock:
            changed = alive != self.alive
            self.alive = alive
            return changed

    def set_phase(self, phase, round_no):
        """Record the phase, and the round number when this packet carries one.

        Most GRI packets omit the round field - it only appears as a round
        turns over - so we keep the last one we saw rather than dropping back
        to unknown. Returns (changed, round_no) with the round we now believe
        we are in.
        """
        with self._lock:
            changed = phase != self.phase
            self.phase = phase
            if round_no is not None:
                self.round_no = round_no
            return changed, self.round_no

    def set_zone(self, center, radius):
        with self._lock:
            self.zone_center = center
            self.zone_radius = radius

    def set_identity(self, full_id):
        with self._lock:
            self.my_id = full_id
            self.my_short = full_id & 0xFF
            if self.joined_at is None:
                self.joined_at = time.time()

    def add_player(self, full_id, name):
        with self._lock:
            self.roster[full_id & 0xFF] = (full_id, name)

    def name_of(self, short_id):
        with self._lock:
            return self.roster.get(short_id, (0, "?"))[1]

    def set_my_pos(self, pos):
        with self._lock:
            self.my_pos = pos

    def seed_my_pos(self, pos):
        """Reconcile our position with the server's view of it.

        Our own stepping is authoritative between updates - letting the echo
        overwrite us every packet cancelled each step and was the original
        'runs on the spot' bug. But the echo is also our only collision
        feedback: when it stops tracking what we command, something solid is in
        the way. We count those refusals, and once the gap is undeniable we
        snap back to the truth so the planner can steer around the obstacle
        instead of burrowing further into an imaginary position.
        """
        with self._lock:
            self.server_pos = pos
            if self.my_pos is None:
                self.my_pos = pos
                self.blocked_ticks = 0
                return
            dx = pos[0] - self.my_pos[0]
            dz = pos[2] - self.my_pos[2]
            gap = math.sqrt(dx * dx + dz * dz)
            if gap > RESYNC_DIST:
                # A teleport, revive or vehicle - the server simply moved us.
                self.my_pos = pos
                self.blocked_ticks = 0
            elif gap > BLOCK_DIST:
                self.blocked_ticks += 1
                if self.blocked_ticks >= BLOCK_TICKS:
                    # Confirmed stuck: adopt the real position so the next step
                    # is planned from where we truly stand.
                    self.my_pos = pos
            else:
                self.blocked_ticks = 0

    def set_target(self, short_id):
        """Point the bot at a new caller. Returns True if anything changed."""
        with self._lock:
            if self.my_short is not None and short_id == self.my_short:
                return False          # never follow ourselves
            if short_id != self.target_short:
                self.target_pos = None
            # A repeat call always clears the motion estimate: reusing a stale
            # velocity sent the bot off along a vector measured minutes ago.
            self.target_vel = (0.0, 0.0)
            self.target_seen_at = None
            self.target_short = short_id
            return True

    def update_target(self, info, now):
        """Fold in one Cmd 2012 record for the player we are following."""
        with self._lock:
            prev_pos, prev_seen = self.target_pos, self.target_seen_at
            self.target_pos = info["pos"]
            self.target_seen_at = now
            if prev_pos is not None and prev_seen is not None:
                dt = now - prev_seen
                if 0.01 < dt < 2.0:
                    vx = (info["pos"][0] - prev_pos[0]) / dt
                    vz = (info["pos"][2] - prev_pos[2]) / dt
                    if abs(vx) < 30000 and abs(vz) < 30000:
                        self.target_vel = (vx, vz)
            self.target_yrot = info.get("yrot", self.target_yrot)
            self.target_aim_y = info.get("aim_y", self.target_aim_y)
            self.target_aim_xz = info.get("aim_xz", self.target_aim_xz)
            # Keep the last stance when a field is absent rather than resetting
            # to standing - that reset is why crouching was only half copied.
            self.target_state = info.get("physx_state", self.target_state)
            self.target_pose = info.get("physx_pose", self.target_pose)

    def sync_clock(self, tick, now):
        with self._lock:
            if self.server_tick is not None and self.tick_local is not None:
                dt = now - self.tick_local
                dtick = tick - self.server_tick
                if dt > 0.5 and 0 < dtick < 10000:
                    self.tick_rate = self.tick_rate * 0.7 + (dtick / dt) * 0.3
            self.server_tick = tick
            self.tick_local = now


class MovementPlanner(object):
    """Decides where to be next. Pure: no I/O, no shared state, no clock.

    Speeds come from measuring the real client: 10 updates a second at a median
    5150 units/second. Matching that keeps the server accepting our movement -
    slower and we never arrive, faster and it reads as a speed hack and gets
    discarded.
    """

    def __init__(self, step=STEP_PER_TICK, max_step=STEP_PER_TICK * 1.25,
                 stick_dist=150.0, far_dist=8000.0, lead=0.2,
                 climb_rate=0.35, coast_limit=3.0, auto_zone=True,
                 fixed_step=None, turn_degrees=0.0, move_in_prepare=True,
                 orbit_fraction=0.55, orbit_speed=6.0, dodge=500.0):
        self.step = float(step)
        self.max_step = float(max_step)
        self.stick_dist = float(stick_dist)
        self.far_dist = float(far_dist)
        self.lead = float(lead)
        self.climb_rate = float(climb_rate)
        self.coast_limit = float(coast_limit)
        self.auto_zone = bool(auto_zone)
        # When set, every axis advances by exactly this many units per tick
        # instead of a normalised stride: (900,900,900) becomes (901,901,901)
        # at a value of 1. Configured by Boot_movement_amount.
        self.fixed_step = fixed_step
        # Degrees of yaw added each tick so the bot sweeps a full circle
        # rather than facing one way the whole match.
        self.turn_degrees = float(turn_degrees)
        # Whether to walk during the buy phase as well as the fight.
        self.move_in_prepare = bool(move_in_prepare)
        # Patrol ring: once inside the zone the bot circles at this fraction of
        # the radius, turning `orbit_speed` degrees each tick.
        self.orbit_fraction = float(orbit_fraction)
        self.orbit_speed = float(orbit_speed)
        self._orbit_angle = 0.0
        self._yaw = 0.0
        # Wall-sliding state. Which way we last swung around an obstacle and
        # when, so we commit to one side long enough to actually clear it
        # instead of oscillating against the corner.
        self._slide_sign = 1.0
        self._slide_until = 0.0
        # How far to step aside when something blocks the path, and how many
        # widening attempts we have made against the current obstacle.
        self.dodge = float(dodge)
        self._dodge_tries = 1

    def next_yaw(self):
        """Advance and return the spin angle, wrapping at a full turn."""
        if self.turn_degrees:
            self._yaw = (self._yaw + self.turn_degrees) % 360.0
        return self._yaw

    def orbit_point(self, snap, now):
        """A point on a circle inside the zone, walked round and round.

        Once the bot is safely inside there is nothing to walk toward, and
        standing still is exactly what gets a player flagged as idle. So it
        patrols a ring at `orbit_fraction` of the radius, advancing along it
        every tick - constant motion that never leaves the zone.

        The angular speed is capped to what our stride can actually keep up
        with. On a ring of 18,700 units, six degrees a tick is an arc of 1,958
        units while the bot can only cover 515 - so the target runs away and
        the bot spirals after it, never settling. Deriving the angle from the
        step size keeps the point exactly one stride ahead.
        """
        if snap.zone_center is None or snap.zone_radius <= 0:
            return None
        cx, cz = snap.zone_center[0], snap.zone_center[-1]
        r = snap.zone_radius * self.orbit_fraction
        if r <= 1:
            return None
        stride = float(self.fixed_step) if self.fixed_step else self.step
        # Degrees the bot can actually travel along this circumference, kept a
        # little under so it stays on the ring rather than trailing it.
        max_deg = math.degrees(stride / r) * 0.85
        self._orbit_angle = (self._orbit_angle
                             + min(self.orbit_speed, max_deg)) % 360.0
        rad = math.radians(self._orbit_angle)
        y = snap.my_pos[1] if snap.my_pos else 0
        return (cx + r * math.cos(rad), y, cz + r * math.sin(rad))

    def aim_point(self, snap, now):
        """Where to head for right now.

        Priority is: a caller who is somewhere safe, otherwise the middle of
        the zone. Following someone into the ring is following them to their
        death, so once the caller strays past ZONE_DANGER_FRACTION of the
        radius we break off for the centre and pick them up again when they
        come back inside.

        With no caller at all - either nobody has called or Chat_CommandUdp is
        off - the zone becomes the standing objective, which is what keeps the
        bot alive through the shrink on its own.

        The caller's position is coasted forward through gaps in the broadcast:
        the server drops distant players from it, so extending their last
        velocity keeps the chase alive instead of stalling at a stale point.
        """
        target = None
        if snap.target_pos is not None:
            if snap.target_seen_at is None:
                target = snap.target_pos
            else:
                gap = now - snap.target_seen_at
                if gap <= 0.05 or gap > self.coast_limit:
                    target = snap.target_pos
                else:
                    vx, vz = snap.target_vel
                    target = (snap.target_pos[0] + vx * gap,
                              snap.target_pos[1],
                              snap.target_pos[2] + vz * gap)

        # A downed teammate outranks everything else. Walking to the caller or
        # the zone while someone is bleeding out is the wrong priority, and the
        # revive cannot start until we are standing next to them anyway.
        rescue = self.rescue_point(snap)
        if rescue is not None:
            return rescue

        zone = self.zone_point(snap)
        if target is None:
            return zone                      # nobody to follow: play the zone
        if zone is not None and self.outside_zone(snap, target):
            return zone                      # caller is in the ring - do not follow
        return target

    def rescue_point(self, snap):
        """Where the nearest downed teammate is, if there is one to reach.

        Once a revive is under way the bot has to stay put for it to finish,
        so the target becomes our own position rather than None - moving away
        mid-revive cancels it.
        """
        if snap.rescuing is not None:
            return snap.my_pos
        if not snap.downed or snap.my_pos is None:
            return None
        best = None
        best_dist = None
        for pos in snap.downed.values():
            if pos is None:
                continue
            dx = pos[0] - snap.my_pos[0]
            dz = pos[2] - snap.my_pos[2]
            dist = math.sqrt(dx * dx + dz * dz)
            if best_dist is None or dist < best_dist:
                best = pos
                best_dist = dist
        return best

    def zone_point(self, snap):
        """Where to be within the zone.

        Far outside, that is the centre - the shortest way back to safety.
        Once inside, it becomes a point on the patrol ring, so the bot keeps
        circling rather than parking on the middle and going still.
        """
        if not self.auto_zone or snap.zone_center is None or snap.zone_radius <= 0:
            return None
        cx, cz = snap.zone_center[0], snap.zone_center[-1]
        y = snap.my_pos[1] if snap.my_pos else 0
        ring = snap.zone_radius * self.orbit_fraction
        if snap.my_pos is not None:
            dx = snap.my_pos[0] - cx
            dz = snap.my_pos[2] - cz
            here = math.sqrt(dx * dx + dz * dz)
            # Join the ring once we are anywhere near it - from inside as well
            # as outside, since the zone shrinks around us and would otherwise
            # leave the bot parked at a centre it has already reached.
            if here <= ring * 1.15:
                # Enter the circle at the angle we are already standing at, so
                # the first orbit step is a short walk rather than a dash to
                # the far side.
                if here > 1:
                    self._orbit_angle = math.degrees(math.atan2(dz, dx))
                orbit = self.orbit_point(snap, 0)
                if orbit is not None:
                    return orbit
        return (cx, y, cz)

    def outside_zone(self, snap, point):
        """True when a point sits far enough out that standing there is fatal."""
        if snap.zone_center is None or snap.zone_radius <= 0:
            return False
        cx, cz = snap.zone_center[0], snap.zone_center[-1]
        dx = point[0] - cx
        dz = point[2] - cz
        return math.sqrt(dx * dx + dz * dz) > snap.zone_radius * ZONE_DANGER_FRACTION

    def plan(self, snap, now):
        """Return (new_pos, dist, moving) or None when we cannot act yet.

        Movement is gated on the fight phase. A captured match shows the round
        cycle plainly - a 24-second buy period, then combat - and the server
        does not expect a player to be running around during the buy. Holding
        position until PHASE_FIGHT arrives is what the client does, so we do
        the same and only report our position in the meantime.

        With no caller we still return our current position rather than None.
        The real client transmits its state ten times a second whether or not
        it is moving, and a bot that goes silent instead is treated as idle -
        so when it finally does move, the server has already stopped listening.
        """
        if snap.my_pos is None or snap.my_id is None:
            return None
        my = snap.my_pos
        # No GRI yet means we do not know whether the round has started, and
        # walking on that assumption is what had the bot heading for the zone
        # before the server had told it anything. Hold still until a phase
        # actually arrives; the position is still reported every tick, so we
        # are present and simply not moving.
        if snap.phase is None:
            return my, 0.0, False
        # Phases where walking makes sense. PREPARE is the buy period, and
        # real players do move around during it, so it is included by default;
        # only the round-end settle keeps us still.
        moving_phases = (PHASE_FIGHT,)
        if self.move_in_prepare:
            moving_phases = (PHASE_FIGHT, PHASE_PREPARE, 2, 9)
        if snap.phase not in moving_phases:
            return my, 0.0, False

        aim = self.aim_point(snap, now)
        if aim is None:
            return my, 0.0, False

        dx = aim[0] - my[0]
        dz = aim[2] - my[2]
        dist = math.sqrt(dx * dx + dz * dz)

        # Follow their height as well. Holding our own Y is what left the bot
        # standing on top of the player on roofs and slopes; easing into theirs
        # reads as walking up rather than teleporting.
        ty = aim[1] if len(aim) > 2 else my[1]
        new_y = int(my[1] + (ty - my[1]) * self.climb_rate)

        # Lead a moving target only while there is ground to cover - predicting
        # at point-blank range just makes the bot wobble around them.
        if dist > self.far_dist:
            vx, vz = snap.target_vel
            dx += vx * self.lead
            dz += vz * self.lead
            dist = math.sqrt(dx * dx + dz * dz)

        if dist <= self.stick_dist or dist < 1e-6:
            # Arrived. Hold station but keep settling onto their height, and
            # keep reporting - a player that stops sending looks disconnected.
            return (my[0], new_y, my[2]), dist, False

        # Blocked? Do not grind against the same wall. Step squarely sideways,
        # and widen the sidestep each time the last one failed: 500, then 1000,
        # then 1500, alternating sides. A fixed nudge is not enough for a wide
        # obstacle and the bot stays wedged against it forever; escalating
        # always finds a way out eventually. A sidestep that would leave the
        # zone is flipped to the other side.
        if snap.blocked_ticks >= BLOCK_TICKS:
            if now > self._slide_until:
                self._slide_sign = -self._slide_sign
                self._slide_until = now + SLIDE_HOLD
                self._dodge_tries = min(self._dodge_tries + 1, 6)
            reach = self.dodge * self._dodge_tries
            ux, uz = dx / dist, dz / dist
            px, pz = -uz * self._slide_sign, ux * self._slide_sign
            side = (my[0] + px * reach, new_y, my[2] + pz * reach)
            if snap.zone_center is not None and self.outside_zone(snap, side):
                px, pz = -px, -pz
                self._slide_sign = -self._slide_sign
                side = (my[0] + px * reach, new_y, my[2] + pz * reach)
            return (int(side[0]), new_y, int(side[2])), dist, True
        self._dodge_tries = 1

        # Move along the straight line to the aim point. The step is the
        # configured amount when one is set, and never overshoots the target.
        # Stepping each axis by the full amount instead - the earlier
        # behaviour - made diagonal travel 1.4x too fast and, worse, made the
        # bot jitter back and forth once an axis difference fell below the
        # step, because only the sign of dx and dz was used.
        step = float(self.fixed_step) if self.fixed_step else (
            self.max_step if dist > self.far_dist else self.step)
        move = step if dist > step else dist
        nx = my[0] + dx / dist * move
        nz = my[2] + dz / dist * move
        return (int(nx), new_y, int(nz)), dist, True

    def server_time(self, snap, now):
        """ServerTimeOnClient in milliseconds since the match began.

        Confirmed against the real client: its packets carry values rising
        ~1000/second, while Cmd 2000 ticks at 30/second - so the conversion is
        tick * 1000 / rate. A unix timestamp here is rejected outright.

        When Cmd 2000 has not arrived this counts from when we joined instead
        of returning zero. That zero was the whole problem with reviving: a
        captured StartRescure carries 38247 in this field, ours carried 0, and
        the server dropped the request without so much as a refusal. Some
        sessions simply never send Cmd 2000, so waiting for it means never
        reviving at all.
        """
        if snap.server_tick is None:
            if snap.joined_at is None:
                return 0
            return int((now - snap.joined_at) * 1000.0) & 0xFFFFFFFF
        elapsed = now - snap.tick_local
        ticks = snap.server_tick + elapsed * snap.tick_rate
        return int(ticks * 1000.0 / snap.tick_rate) & 0xFFFFFFFF


class PacketRouter(object):
    """Dispatch table: command id -> handler.

    Each handler is isolated. One malformed packet raises inside its own
    handler and is logged; every other command carries on. In the old design a
    single bad body could take down the loop that also drove movement.
    """

    def __init__(self, session):
        self.session = session
        self.handlers = {
            CMD_PLAYER_JOIN: session.on_player_join,
            CMD_MATCH_END: session.on_match_end,
            CMD_DEAD: session.on_down,
            CMD_KNOCK_DOWN: session.on_down,
            CMD_KNOCK_REVIVE: session.on_revive_done,
            CMD_START_RESCUE: session.on_rescue_started,
            CMD_STOP_RESCUE: session.on_rescue_stopped,
            CMD_PENDING_REVIVE: session.on_down,
            CMD_REVIVE: session.on_revive,
            CMD_GRI_SYNC: session.on_gri,
            CMD_SERVER_TIME: session.on_server_time,
            CMD_MY_STATE: session.on_my_state,
            CMD_OTHER_STATES: session.on_other_states,
            CMD_QUICK_CHAT: session.on_quick_chat,
            CMD_SAFE_ZONE: session.on_safe_zone,
        }

    def dispatch(self, cmd, body, now):
        handler = self.handlers.get(cmd)
        if handler is None:
            # Nothing is registered for this command. Before the join lands
            # that silence is expensive: if the server is refusing us it says
            # so in a message we have no handler for, and dropping it without
            # a word leaves only "6 packets in, no join" to work from. Once we
            # are in the match the same messages are just noise, so this only
            # reports until then.
            if self.session.state.snapshot().my_id is None:
                self.session.say("    <- %s (%d) %s"
                                 % (ff_udp.cmd_name(cmd) or "unknown", cmd,
                                    body.hex()[:80] if body else "<empty>"))
            return
        if body is None:
            return
        try:
            handler(body, now)
        except Exception as exc:
            self.session.log_error("handler for cmd %d: %r" % (cmd, exc))


class MatchSession(object):
    """Owns the socket and runs the match.

    Two threads, deliberately independent:

      receiver  drains the socket, acks reliable packets, routes bodies
      sender    ticks at a fixed 10Hz and uploads our position

    Neither can stop the other. Each wraps its whole body so a fault costs one
    iteration, never the session - the previous design had the tick arithmetic
    outside its guard, so one exception silently ended all movement while the
    bot kept transmitting a stale position.
    """

    def __init__(self, ip, port, account_id, secret, udp_jwt, udp_mask,
                 suffix="0", verbose=True, api_token=None, match_id=0):
        self.addr = (ip, int(port))
        self.account_id = int(account_id)
        self.secret = secret
        self.key = bytes.fromhex(secret)
        self.order = ff_udp.parse_udp_mask(udp_mask)
        self.jwt = udp_jwt
        # Field 36 of MatchmakingSussNtf. The join carries it and the server
        # matches on it, so a zero here is a join for no match in particular.
        self.match_id = int(match_id or 0)
        # Lobby token for the end-of-match HTTP calls. Separate from the UDP
        # join token, which is only good for the match server.
        self.api_token = api_token
        self.suffix = suffix
        self.verbose = verbose

        self.state = MatchState()
        self.planner = MovementPlanner(
            auto_zone=config.AutoSafeZone,
            fixed_step=getattr(config, "Boot_movement_amount", None),
            turn_degrees=getattr(config, "Boot_turn_degrees", 0.0),
            move_in_prepare=getattr(config, "Boot_move_in_prepare", True),
            orbit_fraction=getattr(config, "Boot_orbit_fraction", 0.55),
            orbit_speed=getattr(config, "Boot_orbit_speed", 6.0),
            dodge=getattr(config, "Boot_dodge_amount", 500.0))
        self.router = PacketRouter(self)
        self.sock = None
        self._stop = threading.Event()
        self._threads = []
        self.errors = 0
        self.match_over = False
        self._stopped = False
        # Counted so a failed join can say what actually happened rather than
        # leaving us to guess between a dead address, a bad key and a refused
        # token.
        self.packets_in = 0
        self.decoded_in = 0
        self._last_rescue_send = 0.0

    # -- logging ---------------------------------------------------------

    def say(self, msg):
        if self.verbose:
            print(msg)

    def log_error(self, msg):
        self.errors += 1
        if self.verbose and self.errors <= 20:
            print("[warn] %s" % msg)

    # -- packet handlers -------------------------------------------------

    def on_player_join(self, body, now):
        """Cmd 101 announces a player. Builds the roster and finds us in it."""
        info = ff_udp.parse_player_join(body)
        if info is None:
            return
        uid, full_id, name = info
        self.state.add_player(full_id, name)
        self.say("join: short=%-3d id=0x%08X %s" % (full_id & 0xFF, full_id, name))
        if uid == self.account_id:
            self.state.set_identity(full_id)
            self.say(">>> that is us: 0x%08X (short %d)" % (full_id, full_id & 0xFF))

    def on_match_end(self, body, now):
        """Cmd 103. The match is over - claim the result the way the client does.

        A full capture of a real Clash Squad match settles how this actually
        goes. The client does NOT send a quit packet: across 149 distinct
        messages there is no C2S_191 and no S2C_192 at all. What it does is
        wait, then send C2S_RUDP_ShowMatchResult_Req - a class with no fields,
        so an empty body - and let the server close things out:

            t=283.2  S2C_103   MatchEnd
            t=287.8  S2C_371   (server, mid-sequence)
            t=288.7  C2S_407   ShowMatchResult, empty, reliable
            t=295.9  S2C_19862/19867/19866/19750  final server messages

        The 5.5s pause before 407 is the part that is easy to get wrong.
        Firing it the instant 103 lands does not match the client, so we hold
        for the same interval and let the server's own end-of-match traffic
        play out first.
        """
        if self.match_over:
            return
        self.match_over = True
        rank = body[1] if len(body) > 1 else 0
        if not config.AutoXp:
            self.say("match end (rank ~%d) - AutoXp off, not claiming" % rank)
            return
        self.say("match end (rank ~%d) - claiming result in %.1fs"
                 % (rank, MATCH_RESULT_DELAY))
        threading.Timer(MATCH_RESULT_DELAY, self._claim_result).start()

    def _claim_result(self):
        """Close the match out the way a finishing client does.

        Two halves. Over UDP, C2S_RUDP_ShowMatchResult_Req: command id only,
        empty body, reliable. Then the lobby calls the client makes the instant
        the result screen appears - six POSTs with an empty encrypted body,
        which the bot never sent because it just went quiet after the match.

        Once both are away the UDP session has nothing left to do, so we close
        it rather than sit on a socket the server has finished with.
        """
        try:
            self.say("requesting match result")
            self.send(CMD_SHOW_MATCH_RESULT, b"",
                      send_option=ff_udp.SENDOPTION_RELIABLE)
        except Exception as exc:
            self.log_error("claim result: %r" % exc)
        if match_api is not None and self.api_token:
            try:
                match_api.claim_match_rewards(self.api_token,
                                              verbose=self.verbose)
            except Exception as exc:
                self.log_error("lobby calls: %r" % exc)
        # The real client keeps talking for about five seconds after the
        # result request - state, acks, pings - and then simply falls silent.
        # It never sends a quit command. So we hold the session open the same
        # way instead of cutting off mid-exchange. Only the flag is set here:
        # run() owns the teardown, and calling stop() from this thread would
        # have it try to join the threads it is running on.
        def finish():
            self.say("match complete - leaving")
            self._stop.set()
        threading.Timer(POST_RESULT_LINGER, finish).start()

    def on_down(self, body, now):
        """Cmd 107 / 139 / 256: someone died, was knocked down or went into the
        downed state. Each carries the PlayerID first.

        Two things happen here. If it is us, movement stops - a corpse that
        keeps streaming position is exactly the pattern an anti-cheat looks
        for. If it is a teammate, they go straight onto the downed list.

        That second half was missing, and it is why the bot walked over and
        then stood there: the list was only ever filled from the knockdown
        pose in Cmd 2012, and a downed player does not reliably appear in that
        broadcast with pose 8. A captured knockdown showed Cmd 139 naming the
        player outright - 0x02000002, in the first field - while the same
        session's Cmd 2012 samples reported everyone standing.
        """
        if not body:
            return
        pid, _ = ff_udp._rd_varint(body, 0)
        snap = self.state.snapshot()
        if snap.my_id is not None and pid == snap.my_id:
            if self.state.set_alive(False):
                self.say("we are down - holding position updates")
            return
        short = pid & 0xFF
        if short in self.state.teammates():
            self.state.mark_down(short)
            self.say("teammate 0x%08X is down" % pid)

    def on_revive(self, body, now):
        """Cmd 393: back on our feet, resume normal movement."""
        if not body:
            return
        pid, _ = ff_udp._rd_varint(body, 0)
        snap = self.state.snapshot()
        if snap.my_id is None or pid != snap.my_id:
            return
        if self.state.set_alive(True):
            self.say("revived - resuming")

    def on_rescue_started(self, body, now):
        """Cmd 142 coming back is S2C_RUDP_StartRescure_Res.

        It carries Success and a FailReason, decoded from a real revive:

            01          Success
            0x02000004  PlayerID        - who is reviving
            0x02000002  TargetPlayerID  - who is down
            38247       StartRescureTime
            41247       StopRescureTime - 3000 ticks later
            00          FailReason
            02          RescueMethod

        The two timestamps sit between the target and the reason; reading the
        reason without skipping them landed on the wrong byte entirely.

        On success we do nothing but wait. A captured revive has no C2S_143
        anywhere in it: the client sends 142 and the server closes the revive
        out with Cmd 140 by itself, 2.9s later. Sending our own stop was
        inventing a message the game never sends.
        """
        if not body:
            return
        success = body[0] != 0
        try:
            player_id, pos = ff_udp._rd_varint(body, 1)
            target_id, pos = ff_udp._rd_varint(body, pos)
            start_time, pos = ff_udp._rd_varint(body, pos)
            stop_time, pos = ff_udp._rd_varint(body, pos)
        except Exception:
            return
        if success:
            self.say("reviving 0x%08X - server holds it for %d ticks"
                     % (target_id, stop_time - start_time))
            return
        reason = body[pos] if pos < len(body) else 0
        if self.state.end_rescue(target_id) is not None:
            self.say("revive of 0x%08X refused (reason %d)"
                     % (target_id, reason))

    def on_rescue_stopped(self, body, now):
        """Cmd 143 coming back is S2C_RUDP_StopRescure_Res - the revive is over,
        successfully or not, so the slot is free for the next teammate."""
        if not body:
            return
        try:
            player_id, pos = ff_udp._rd_varint(body, 0)
            target_id, pos = ff_udp._rd_varint(body, pos)
        except Exception:
            return
        self.state.end_rescue(target_id)

    def on_revive_done(self, body, now):
        """Cmd 140. Captured as 8280801084808010e20902:

            0x02000002  PlayerID  - who was revived
            0x02000004  RescureID - who revived them
            1250        TickCount

        The first field is the target, which is what the pending rescue is
        keyed by.
        """
        if not body:
            return
        try:
            target_id, pos = ff_udp._rd_varint(body, 0)
        except Exception:
            return
        if self.state.end_rescue(target_id) is not None:
            self.say("revived 0x%08X" % target_id)

    def on_gri(self, body, now):
        """Cmd 1901. Carries the round number and the phase within it.

        Only some GRI packets include the phase field; the rest just bump a
        counter, so a None result here is normal and not an error. Movement is
        gated on this: the bot holds still through the buy period and starts
        walking when PHASE_FIGHT opens, which is the rhythm the real client
        follows.
        """
        parsed = ff_udp.get_match_phase(body)
        if parsed is None:
            return
        phase, round_no, start, end = parsed
        changed, current_round = self.state.set_phase(phase, round_no)
        if changed:
            self.say("round %s: %s (%d -> %d)"
                     % (current_round if current_round is not None else 1,
                        PHASE_NAMES.get(phase, "phase%d" % phase), start, end))

    def on_server_time(self, body, now):
        """Cmd 2000 carries ServerGameTickCount and a phase byte."""
        parsed = ff_udp.decode_server_time(body)
        if parsed is None:
            return
        tick = parsed[0] if isinstance(parsed, tuple) else parsed
        self.state.sync_clock(tick, now)
        # The real client answers every sync; one that never replies looks out
        # of step and the server starts ignoring its movement.
        self.send(CMD_SERVER_TIME, ff_udp.build_server_time_ack(tick))

    def on_my_state(self, body, now):
        """Cmd 2001 carries only us - the server's view of our own player."""
        if len(body) < 4:
            return
        # Read the header as varints rather than assuming one byte each. Both
        # MatchTime and the count are varint-encoded, so a fixed offset happens
        # to work only while they stay small.
        _mt, p = ff_udp._rd_varint(body, 0)
        _count, p = ff_udp._rd_varint(body, p)
        p += 1                                 # short id, already known
        x, p = ff_udp._rd_varint(body, p)
        y, p = ff_udp._rd_varint(body, p)
        z, p = ff_udp._rd_varint(body, p)
        self.state.seed_my_pos((ff_udp._zag(x), ff_udp._zag(y), ff_udp._zag(z)))

    def on_other_states(self, body, now):
        """Cmd 2012 carries every player except us.

        Positions for the downed list are filled in here, but the list is not
        rebuilt from this packet. Overwriting it every tick meant a teammate
        announced by Cmd 139 vanished again the moment the next broadcast
        arrived without pose 8 - which is most of them.
        """
        players = ff_udp.decode_players_v2(body)
        snap = self.state.snapshot()
        mates = self.state.teammates()
        for short, info in players.items():
            if short not in mates:
                continue
            if info.get("physx_pose") == POSE_KNOCKDOWN:
                self.state.mark_down(short, info["pos"])
            elif short in snap.downed:
                self.state.clear_down(short)
        if snap.target_short is None:
            return
        info = players.get(snap.target_short)
        if info is not None:
            self.state.update_target(info, now)

    def on_quick_chat(self, body, now):
        """Cmd 551. Only follow/help retarget us - other canned lines are
        chatter, and reacting to them made the bot chase whoever spoke last."""
        if not config.Chat_CommandUdp:
            return                             # callers ignored by configuration
        parsed = ff_udp.decode_quick_chat(body)
        if parsed is None:
            return
        chat_id, short_id, raw, team = parsed
        if chat_id not in FOLLOW_TRIGGERS:
            return
        if self.state.set_target(short_id):
            self.say("'%s' from 0x%08X -> following %d (%s)"
                     % (CHAT_NAMES.get(chat_id, chat_id), raw, short_id,
                        self.state.name_of(short_id)))

    def on_safe_zone(self, body, now):
        """Cmd 117. We track the ring so the bot can break off and run for the
        middle when the caller wanders into it."""
        zone = ff_udp.decode_safezone(body)
        if zone is None:
            return
        center = zone.get("inner_center") or zone.get("outer_center")
        radius = zone.get("inner_radius") or zone.get("outer_radius") or 0
        if not center or radius <= 0:
            return
        self.state.set_zone(center, radius * ZONE_RADIUS_SCALE)
        snap = self.state.snapshot()
        if snap.my_pos is not None:
            dx = snap.my_pos[0] - center[0]
            dz = snap.my_pos[2] - center[-1]
            dist = math.sqrt(dx * dx + dz * dz)
            self.say("zone: centre=%s radius=%d (we are %.0f away, %s)"
                     % (center, radius, dist,
                        "inside" if dist <= radius * ZONE_RADIUS_SCALE else "OUTSIDE"))
        else:
            self.say("zone: centre=%s radius=%d" % (center, radius))

    # -- socket ----------------------------------------------------------

    def send(self, cmd, body, send_option=None, flags=None):
        """Fire one datagram. Never raises: a transient socket error must not
        propagate into a loop that other behaviour depends on."""
        if self.sock is None:
            return False
        if send_option is None:
            send_option = ff_udp.SENDOPTION_NONE
        if flags is None:
            flags = ff_udp.FLAG_ENCRYPTED
        try:
            pkt = ff_udp.encode_packet(cmd, send_option, 0, flags,
                                       body, self.key, self.order)
            self.sock.sendto(pkt, self.addr)
            return True
        except Exception as exc:
            self.log_error("send cmd %d: %r" % (cmd, exc))
            return False

    # -- threads ---------------------------------------------------------

    def _receiver(self):
        while not self._stop.is_set():
            try:
                try:
                    data, _ = self.sock.recvfrom(4096)
                except socket.timeout:
                    continue
                now = time.time()
                self.packets_in += 1
                dec = ff_udp.decode_packet(data, self.key, self.order)
                if dec is None:
                    continue
                self.decoded_in += 1
                if dec["reliable"]:
                    req = (dec["OrderID"] + 1) & 0xFFFF
                    self.send(ff_udp.CMD_ACK, ff_udp.build_ack_body(req))
                self.router.dispatch(dec["Cmd"], dec["body"], now)
            except Exception as exc:
                self.log_error("receiver: %r" % exc)
                time.sleep(0.01)

    def _sender(self):
        next_tick = time.time()
        next_ping = time.time()
        while not self._stop.is_set():
            try:
                now = time.time()
                if now >= next_ping:
                    ff_udp.send_ping(self.sock, self.addr, self.key, self.order)
                    next_ping = now + ff_udp.PING_INTERVAL
                if now < next_tick:
                    time.sleep(min(0.02, next_tick - now))
                    continue
                # Anchor to the previous tick so a slow pass does not
                # permanently shift the cadence away from the client's 10Hz.
                next_tick += MOVE_INTERVAL
                if next_tick < now:
                    next_tick = now + MOVE_INTERVAL

                snap = self.state.snapshot()
                # Dead or downed: send nothing at all. A body that keeps
                # reporting movement is the clearest tell there is.
                if not snap.alive:
                    continue
                self._tend_downed(snap, now)
                planned = self.planner.plan(snap, now)
                if planned is None:
                    continue
                new_pos, dist, moving = planned
                old_pos = snap.my_pos
                self.state.set_my_pos(new_pos)
                # Sweep the view around as we go, so the bot is not frozen
                # staring in one direction the whole match.
                yaw = self.planner.next_yaw()
                ff_udp.send_position(
                    self.sock, self.addr, self.key, self.order,
                    snap.my_id, old_pos, new_pos,
                    self.planner.server_time(snap, now),
                    aim_yaw=yaw,
                    target_yrot=snap.target_yrot,
                    target_aim_y=snap.target_aim_y,
                    target_aim_xz=snap.target_aim_xz,
                    copy_state=snap.target_state,
                    copy_pose=snap.target_pose)
            except Exception as exc:
                self.log_error("sender: %r" % exc)
                time.sleep(MOVE_INTERVAL)

    # -- lifecycle -------------------------------------------------------

    def _tend_downed(self, snap, now):
        """Start a revive when a teammate is down and we are close enough.

        Only teammates reach here - on_other_states filters by the team byte -
        and our own record never appears in Cmd 2012 at all, so there is no
        way to end up trying to revive ourselves.

        The stop is normally sent when Cmd 140 confirms the revive, but a
        revive that is interrupted produces no confirmation, so it is also
        given up on after REVIVE_TIMEOUT rather than blocking every later one.
        """
        if snap.rescuing is not None:
            if now - snap.rescue_started > REVIVE_TIMEOUT:
                abandoned = self.state.end_rescue()
                if abandoned is not None:
                    self.say("revive of 0x%08X timed out" % abandoned)
            elif now - self._last_rescue_send >= REVIVE_RETRY:
                # Nothing came back - not an accept, not a refusal. UDP loses
                # packets and this one is the difference between reviving and
                # standing there, so it is repeated until the server answers
                # one way or the other.
                self._last_rescue_send = now
                self.send(CMD_START_RESCUE,
                          ff_udp.build_start_rescue_body(
                              snap.rescuing,
                              self.planner.server_time(snap, now)),
                          send_option=ff_udp.SENDOPTION_RELIABLE)
            return
        if not snap.downed or snap.my_pos is None:
            return
        short = self._nearest_downed(snap)
        if short is None:
            return
        full_id = self.state.full_id_of(short)
        if full_id is None or full_id == snap.my_id:
            return
        if not self.state.begin_rescue(full_id, now):
            return
        self._last_rescue_send = now
        self.send(CMD_START_RESCUE,
                  ff_udp.build_start_rescue_body(
                      full_id, self.planner.server_time(snap, now)),
                  send_option=ff_udp.SENDOPTION_RELIABLE)
        self.say("reviving 0x%08X (short %d)" % (full_id, short))

    def _nearest_downed(self, snap):
        """The closest downed teammate within reviving distance, if any.

        A teammate known only from Cmd 139 has no position yet. Those are
        returned too, because the revive request only needs their PlayerID -
        the server judges the distance, and refusing to ask until Cmd 2012
        happens to report them is how the bot ended up standing over someone
        doing nothing.
        """
        best = None
        best_dist = REVIVE_RANGE
        for short, pos in snap.downed.items():
            if pos is None:
                if best is None:
                    best = short
                continue
            dx = pos[0] - snap.my_pos[0]
            dz = pos[2] - snap.my_pos[2]
            dist = math.sqrt(dx * dx + dz * dz)
            if dist <= best_dist:
                best = short
                best_dist = dist
        return best

    def run(self, game_mode=15, map_id=1, match_mode=1, sleep_ms=0,
            timeout=3.0):
        """Handshake, join, then run until stopped."""
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.settimeout(0.2)
        self.say("=== match session -> %s:%d ===" % self.addr)

        if not self._handshake(timeout):
            self.say("handshake failed")
            return False

        # The receiver starts BEFORE the join goes out. Both 447 and 448 are
        # reliable packets and the server expects an ack for each; sending
        # them first meant nothing was listening to ack with, so the server
        # re-sent its handshake and never processed the join at all - the
        # capture showed HelloRes arriving twice and no Cmd 101 ever.
        for fn in (self._receiver, self._sender):
            th = threading.Thread(target=fn, daemon=True)
            th.start()
            self._threads.append(th)

        self._join(game_mode, map_id, match_mode, sleep_ms)
        self.say("running - press Ctrl+C to stop")
        # Watch for the join actually landing. The handshake succeeding only
        # proves the match server is reachable; if 447/448 are refused the
        # session sits there silently forever, which is indistinguishable from
        # working until you notice no player ever joined.
        threading.Timer(JOIN_TIMEOUT, self._check_joined).start()
        try:
            while not self._stop.is_set():
                time.sleep(0.5)
        except KeyboardInterrupt:
            self.say("stopping")
        finally:
            self.stop()
        return True

    def _check_joined(self):
        """Report whether the match server ever answered our join.

        Called once, a few seconds in. Nothing is retried here - if the join
        was refused the token is spent and the only cure is a fresh match - but
        saying so plainly beats a session that looks alive and is not.
        """
        if self._stop.is_set():
            return
        snap = self.state.snapshot()
        if snap.my_id is not None:
            return
        self.say("no join after %.0fs - the match server did not accept us"
                 % JOIN_TIMEOUT)
        self.say("    packets in: %d, decoded: %d"
                 % (self.packets_in, self.decoded_in))
        if self.packets_in == 0:
            self.say("    nothing arrived at all - wrong address, or the "
                     "match was already gone")
        elif self.decoded_in == 0:
            self.say("    packets arrived but none decrypted - wrong secret "
                     "or mask")
        else:
            self.say("    traffic decodes but no Cmd 101 - the join token was "
                     "refused (expired, or the slot is taken)")
        self._stop.set()

    def _handshake(self, timeout):
        hello = ff_udp.build_hello_body(self.account_id, self.suffix)
        deadline = time.time() + timeout
        while time.time() < deadline:
            self.send(ff_udp.CMD_HELLO, hello,
                      send_option=ff_udp.SENDOPTION_HELLO)
            try:
                data, _ = self.sock.recvfrom(4096)
            except socket.timeout:
                continue
            dec = ff_udp.decode_packet(data, self.key, self.order)
            if dec is not None and dec["Cmd"] == ff_udp.CMD_HELLO:
                # Ack against the OrderID in the packet HEADER, not the one
                # parse_hello_res reports. That body field is always 0 - the
                # sequence number lives in the header - so the ack named the
                # wrong packet, the server never saw its handshake confirmed,
                # and it kept re-sending HelloRes instead of processing the
                # join. The receive loop already acks this way.
                req = (dec["OrderID"] + 1) & 0xFFFF
                self.send(ff_udp.CMD_ACK, ff_udp.build_ack_body(req))
                return True
        return False

    def _join(self, game_mode, map_id, match_mode, sleep_ms):
        part1, part2 = ff_udp.split_token(self.jwt)
        time.sleep(sleep_ms / 1000.0)
        pkt = ff_udp.encode_packet(447, ff_udp.SENDOPTION_RELIABLE, 0,
                                   ff_udp.FLAG_ENCRYPTED,
                                   ff_udp.build_join_prepare_body(part1),
                                   self.key, self.order)
        try:
            self.sock.sendto(pkt, self.addr)
        except Exception as exc:
            self.log_error("send 447: %r" % exc)
        time.sleep(sleep_ms / 1000.0)
        # The match id goes in the join. A real C2S_448 carries it three times
        # over - RoomID, ServiceRoomID and once more among the optional fields
        # - and we were sending zero for all three.
        body = ff_udp.build_join_post_body(self.account_id, part2,
                                           game_mode=game_mode, map_id=map_id,
                                           match_mode=match_mode,
                                           match_id=self.match_id)
        pkt = ff_udp.encode_packet(448, ff_udp.SENDOPTION_RELIABLE, 1,
                                   ff_udp.FLAG_ENCRYPTED, body,
                                   self.key, self.order)
        try:
            self.sock.sendto(pkt, self.addr)
            # Printed because the join is the one message that either works or
            # silently does not, and when it does not this is the only record
            # of what we actually claimed.
            self.say("    -> 448 join: match_id=%d mode=%d map=%d (%d bytes)"
                     % (self.match_id, game_mode, map_id, len(body)))
        except Exception as exc:
            self.log_error("send 448: %r" % exc)

    def stop(self):
        """Tear the session down. Safe to call more than once."""
        if self._stopped:
            return
        self._stopped = True
        self._stop.set()
        for th in self._threads:
            # Never join the thread we are running on - that deadlocks until
            # the timeout and was why 'stopped' printed twice.
            if th is not threading.current_thread():
                th.join(timeout=1.0)
        if self.sock is not None:
            try:
                self.sock.close()
            except Exception:
                pass
        self.say("stopped (%d warnings)" % self.errors)


def run_match(ip, port, account_id, secret, udp_jwt, udp_mask, suffix="0",
              game_mode=15, map_id=1, match_mode=1, sleep_ms=0, timeout=3.0,
              api_token=None, match_id=0, verbose=True):
    """Entry point matching the old handler_full signature.

    api_token is the lobby JWT, used only for the end-of-match HTTP calls.
    match_id comes from MatchmakingSussNtf field 36 and goes into the join.
    verbose=False silences the session, which is what a caller running several
    matches at once wants - four of these narrating at the same time is not a
    log anyone can read.
    """
    session = MatchSession(ip, port, account_id, secret, udp_jwt, udp_mask,
                           suffix=suffix, api_token=api_token,
                           match_id=match_id, verbose=verbose)
    return session.run(game_mode=game_mode, map_id=map_id,
                       match_mode=match_mode, sleep_ms=sleep_ms,
                       timeout=timeout)
