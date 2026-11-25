import threading

HowManyMatch = 50
UnlimitedMatch = True

# How to sign in. isGuest reads account_id.json (uid + password) and asks
# Garena for a token. IsAccessToken skips that: it reads access_token.json,
# inspects the token to learn open_id and platform, and goes straight to
# MajorLogin. Turn on exactly one.
isGuest = True
IsAccessToken = False

# Play one match at a time and let each one finish before asking for the next,
# the way the real client does. With this off, lone wolf fires a new start the
# moment the notification lands, so several matches run at once.
Safe_Mode = False

# In safe mode, stop the script once HowManyMatch is reached. Off means keep
# playing with no limit.
IsSafeModeSelected_numberofgames = False

# How long to sit in the lobby between matches in safe mode. A real player
# lands on the result screen, waits for it, and taps play again - rarely at
# the same speed twice. A fixed number plus jitter gives a hard floor and a
# flat spread, which is its own pattern; a range sampled toward the middle
# looks like someone playing rather than a timer firing.
Safe_Mode_Gap_Min = 8.0
Safe_Mode_Gap_Max = 19.0

# How long to wait for the end-of-match claim after the session ends. The
# claim runs on a timer inside the match code, so safe mode holds here until
# the XP has actually been asked for.
Claim_Wait = 45.0

Clash_Mode = 15
LoneWolf_Mode = 43
LoneWolf_MinLevel = 3

Udp_Match_Timeout = 900.0
Shutdown_Delay = 30.0
Xp_Settle_Delay = 8.0

# How rarely GetPlayerPersonalShow may be asked for the name, level and xp.
# A supervisor wants a number every second; the server does not. This floor is
# what keeps the request rate at match pace no matter how often anything is
# watching - readers inside the gap get the last answer out of memory.
# Profile_Force_Gap is the shorter floor used right after a round ends, when a
# change is actually likely.
Profile_Min_Gap = 25.0
Profile_Force_Gap = 5.0

# How long before the login token expires to renew it. The renewal restarts
# the process in place, which is the only way to get a fresh MajorLogin, so it
# has to happen while the old token still works rather than after it stops.
Token_Renew_Margin = 300.0

Use_Skill = True

AntiAFK = True
Buy_In_Prepare = True
Fire_In_Fight = True
Switch_Weapon = True

Action_Interval = 3.0
Fire_Burst_Ticks = 6
Buy_Items = [(25, 1), (33, 2), (305, 2)]

Restart_Delay = 6.0
Retry_Delay = 10.0
Match_Wait = 45.0

Ping_Interval = 3.0
Socket_Timeout = 30.0
Reconnect_Cap = 8.0
Http_Timeout = 20.0

Show_Match = True
Show_Replies = False
Show_Counter = True
Show_Login = True

Boot_movement_amount = 515
Boot_turn_degrees = 120
Boot_move_in_prepare = True
Boot_orbit_fraction = 0.55
Boot_orbit_speed = 6.0
Boot_dodge_amount = 500

AutoSafeZone = True
AutoXp = True
Chat_CommandUdp = False

Fallback_Region = "ME"
Fallback_Skill_Cooldown = 59.0
Fallback_Client_Version = "1.126.22"
Fallback_Release_Version = "OB54"


class Live:
    """Everything the server tells us, discovered at runtime.

    The region arrives in MajorLogin field 3, the country in 4, the anti-cheat
    host list in 16, and the character skill with its exact cooldown in the
    in-match Cmd 250. Hard-coding any of them was a guess that happened to fit
    one account on one day: an account locked to another region, or a character
    with a different skill, silently sent wrong values and the server refused
    or ignored the request.

    Nothing here starts with a real value. A caller that reads before the
    server has spoken gets the matching Fallback_ constant, so the guess is at
    least visible in one place instead of scattered through the code.
    """

    def __init__(self):
        self._lock = threading.Lock()
        self._region = ""
        self._country = ""
        self._anticheat = ()
        self._client_host = ""
        self._client_version = ""
        self._release_version = ""
        self._skill_id = 0
        self._skill_cooldown = 0.0

    def set_login(self, region=None, country=None, anticheat=None,
                  client_host=None):
        with self._lock:
            if region:
                self._region = str(region)
            if country:
                self._country = str(country)
            if anticheat:
                if isinstance(anticheat, str):
                    parts = [h for h in anticheat.split(";") if h]
                else:
                    parts = [h for h in anticheat if h]
                self._anticheat = tuple(parts)
            if client_host:
                self._client_host = str(client_host)

    def set_versions(self, client=None, release=None):
        with self._lock:
            if client:
                self._client_version = str(client)
            if release:
                self._release_version = str(release)

    def set_skill(self, skill_id=None, cooldown=None):
        with self._lock:
            if skill_id:
                self._skill_id = int(skill_id)
            if cooldown and cooldown > 0:
                self._skill_cooldown = float(cooldown)

    def get_region(self):
        with self._lock:
            return self._region or Fallback_Region

    def get_skill_id(self):
        with self._lock:
            return self._skill_id

    def get_skill_cooldown(self):
        with self._lock:
            if self._skill_cooldown > 0:
                return self._skill_cooldown
        return Fallback_Skill_Cooldown

    def anticheat_hosts(self):
        with self._lock:
            return list(self._anticheat)

    @property
    def country(self):
        with self._lock:
            return self._country

    @property
    def client_host(self):
        with self._lock:
            return self._client_host

    @property
    def client_version(self):
        with self._lock:
            return self._client_version or Fallback_Client_Version

    @property
    def release_version(self):
        with self._lock:
            return self._release_version or Fallback_Release_Version


LIVE = Live()
