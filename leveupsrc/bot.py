import json
import os
import random
import socket
import string
import sys
import threading
import time
from datetime import datetime
from typing import Any, Dict, Optional, Tuple

import jwt
import requests
import urllib3
from google.protobuf.timestamp_pb2 import Timestamp

import my_message_pb2
import config
import tools
import toolsudp

_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, _HERE)
import autoupdate

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)


def ping_hex() -> str:
    return "0215"


def ping_interval() -> float:
    return 3.0


def socket_timeout() -> float:
    return 30.0


def reconnect_cap() -> float:
    return 8.0


def http_timeout() -> float:
    return 20.0


def stream_cap() -> int:
    return 4 * 1024 * 1024


def frame_cap() -> int:
    return 1024 * 1024


def garena_token_url() -> str:
    return "https://100067.connect.garena.com/oauth/guest/token/grant"


def garena_secret() -> str:
    return "2ee44819e9b4598845141067b281621874d0d5d7af9d8f7e00c1e54715b7d1e3"


def major_login_url() -> str:
    return autoupdate.server_url() + "/MajorLogin"


def fallback_client_host() -> str:
    return "https://clientbp.ggpolarbear.com"


def unity_version() -> str:
    return "2018.4.11f1"


def user_agent() -> str:
    return "Dalvik/2.1.0 (Linux; U; Android 9; G011A Build/PI)"


def client_version_code() -> str:
    return "2019120772"


def account_file() -> str:
    return os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        "account_id.json")


def token_file() -> str:
    return os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        "access_token.json")


def use_access_token() -> bool:
    return bool(getattr(config, "IsAccessToken", False))


def use_guest() -> bool:
    return bool(getattr(config, "isGuest", False))


def auth_mode() -> str:
    """Which login path to run, from the two flags.

    Both flags are read, and their combination is resolved here in one place
    rather than by whichever check happened to run first. A token needs a
    token file and guest needs an account file, so a wrong pick is not a
    fallback - it reads the wrong file and fails. The rules:
      - exactly one on  -> that one
      - both on         -> token wins, but say so, because it means the config
                           contradicts itself
      - both off        -> refuse, rather than silently defaulting to guest
                           and then failing on a missing account file
    """
    token = use_access_token()
    guest = use_guest()
    if token and guest:
        trace("both isGuest and IsAccessToken are on - using access token")
        return "access_token"
    if token:
        return "access_token"
    if guest:
        return "guest"
    trace("neither isGuest nor IsAccessToken is on - nothing to run")
    return ""


def check_config_fresh() -> None:
    """Warn when a stale .pyc is serving old settings.

    CPython compares config.py's mtime to the cached .pyc at one-second
    resolution. Edit the file within the same second the cache was written and
    the old values keep loading - which is how IsAccessToken=True still ran the
    guest path and failed on a missing uid. Comparing the two timestamps here
    turns a confusing wrong-path failure into a line telling you to delete the
    cache.
    """
    source = getattr(config, "__file__", "")
    cached = getattr(config, "__cached__", "")
    if not source or not cached:
        return
    try:
        if os.path.getmtime(cached) < os.path.getmtime(source):
            emit("config.py is newer than its cache - delete __pycache__")
    except OSError:
        pass


def token_inspect_url(token: str) -> str:
    return ("https://100067.connect.garena.com/oauth/token/inspect?token=%s"
            % token)


def emit(line: str) -> None:
    sys.stdout.write(line + "\n")
    sys.stdout.flush()


def trace(line: str) -> None:
    """Report a failure that would otherwise vanish.

    Three separate faults in this file were invisible because the except that
    caught them did nothing: a decoded string that was really a nested message,
    a race on the match event, and a reference to a setting that had been
    deleted. Each one made the bot exit in silence. Anything that can stop the
    run says so here.
    """
    if getattr(config, "Show_Login", False) or getattr(config, "Show_Replies",
                                                       False):
        emit(line)


def host_of(url: str) -> str:
    return url.split("//", 1)[-1].split("/", 1)[0]


def normalise_host(url: str) -> str:
    cleaned = url.rstrip("/")
    return cleaned if cleaned.startswith("http") else "https://" + cleaned


def entry_text(entry: Any) -> str:
    raw = getattr(entry, "raw_bytes", None)
    if isinstance(raw, (bytes, bytearray)):
        body = strip_header(bytes(raw))
        try:
            return body.decode("utf-8")
        except Exception:
            pass
    value = getattr(entry, "value", None)
    if isinstance(value, str):
        return value
    if isinstance(value, bytes):
        try:
            return value.decode("utf-8")
        except Exception:
            return ""
    return ""


def strip_header(raw: bytes) -> bytes:
    pos = 0
    while pos < len(raw):
        if not raw[pos] & 0x80:
            pos += 1
            break
        pos += 1
    if pos >= len(raw):
        return raw
    length = 0
    shift = 0
    while pos < len(raw):
        byte = raw[pos]
        pos += 1
        length |= (byte & 0x7F) << shift
        shift += 7
        if not byte & 0x80:
            break
    if length and pos + length <= len(raw):
        return raw[pos:pos + length]
    return raw[pos:] if pos < len(raw) else raw


def as_int(value: Any, default: int = 0) -> int:
    if isinstance(value, int):
        return value
    try:
        return int(str(value))
    except Exception:
        return default


def split_address(address: str) -> Tuple[str, str]:
    host, _, port = str(address).rpartition(":")
    return host, port


def dec_to_hex(value: int) -> str:
    return "%02x" % value


def api_headers(token: str = "", host: Optional[str] = None) -> Dict[str, str]:
    headers = {
        "Expect": "100-continue",
        "Authorization": "Bearer %s" % token if token else "Bearer",
        "X-Unity-Version": unity_version(),
        "X-GA": "v1 1",
        "X-GA-SV": "1789535859",
        "ReleaseVersion": autoupdate.release_version(),
        "Content-Type": "application/x-www-form-urlencoded",
        "User-Agent": user_agent(),
        "Connection": "Keep-Alive",
        "Accept-Encoding": "gzip",
    }
    if host:
        headers["Host"] = host
    return headers


def garena_headers() -> Dict[str, str]:
    device = "".join(random.choice(string.ascii_uppercase + string.digits)
                     for _ in range(5))
    locale = "".join(random.choice(string.ascii_lowercase) for _ in range(2))
    country = "".join(random.choice(string.ascii_lowercase) for _ in range(2))
    return {
        "Host": "100067.connect.garena.com",
        "User-Agent": "GarenaMSDK/4.0.19P4(%s ;Android %d;%s;%s;)"
                      % (device, random.randint(6, 13), locale, country),
        "Content-Type": "application/x-www-form-urlencoded",
        "Accept-Encoding": "gzip, deflate, br",
        "Connection": "close",
    }


def login_fields() -> Dict[int, Any]:
    return {
        3: None, 4: "free fire", 7: autoupdate.remote_version(),
        8: "iOS 17.0.2", 9: "Handheld", 10: "--", 11: "WIFI",
        12: 1623, 13: 750, 14: "326", 15: "arm64e | 0 | 6", 16: 2834,
        17: "Apple A12 GPU", 18: "Metal",
        19: "Apple|36AD5348-1CD5-44AB-AD8C-174B6C4BD217",
        20: "105.154.86.59", 21: "ar", 22: None, 23: "4", 24: "Handheld",
        25: "iPhone11,8", 29: None, 30: 1, 62: 122007, 63: 2373, 70: 2,
        73: 2, 76: 1, 77: "IOSDevice", 78: 3, 79: 1,
        83: client_version_code(), 85: 3, 86: "Metal", 87: 511, 88: 4,
        90: "Agadir", 91: "09", 92: 3663, 93: "ios",
        94: "KqsHT1ISLB4g52xG4K5Li1B6QMSv/Z1pnF6YOaKb5Iy/WBFzI6ovYKJ8OILlgQri/OUIEA==",
        97: 1, 100: "4",
    }


def build_login(open_id: str, access_token: str,
                extra: Dict[int, Any], platform: str = "") -> bytes:
    fields = login_fields()
    fields[3] = str(datetime.now())[:-7]
    fields[22] = open_id
    fields[29] = access_token
    fields.update(extra)
    if platform:
        # In access-token mode the inspect call tells us the platform the
        # token was issued for. The client puts it in three places at once -
        # 23, 99 and 100 - so a token minted on Android is not sent under the
        # iOS defaults these fields otherwise carry. These go in AFTER extra
        # on purpose: field 99 also carries the login stage in extra ("4" for
        # MajorLogin, "0" for GetLoginData), and the platform id has to win
        # over that, not be overwritten by it.
        fields[23] = platform
        fields[99] = platform
        fields[100] = platform
    return bytes.fromhex(
        tools.encrypt_api(tools.create_protobuf_packet(fields).hex()))


def parse_handshake(content: bytes) -> Tuple[int, bytes, bytes]:
    message = my_message_pb2.MyMessage()
    message.ParseFromString(content)
    stamp = Timestamp()
    stamp.FromNanoseconds(message.field21)
    return (stamp.seconds * 1_000_000_000 + stamp.nanos,
            message.field22, message.field23)


def safe_mode() -> bool:
    return bool(getattr(config, "Safe_Mode", False))


def counted_run() -> bool:
    """Whether the run stops after HowManyMatch.

    Safe mode has its own switch because the two questions are separate: one is
    how fast to play, the other is whether to stop. Outside safe mode the older
    UnlimitedMatch still decides.
    """
    if safe_mode():
        return bool(getattr(config, "IsSafeModeSelected_numberofgames", True))
    return not bool(getattr(config, "UnlimitedMatch", False))


def safe_gap() -> float:
    """A lobby pause that varies the way a person does.

    Averaging two draws gives a triangular spread: most pauses land near the
    middle of the range, a few at either edge, and none exactly repeat. A
    constant plus uniform jitter has a hard floor and a flat top, which is as
    much of a signature as no delay at all.
    """
    low = float(getattr(config, "Safe_Mode_Gap_Min", 8.0) or 0.0)
    high = float(getattr(config, "Safe_Mode_Gap_Max", 19.0) or 0.0)
    if high <= low:
        return max(0.0, low)
    return (random.uniform(low, high) + random.uniform(low, high)) / 2.0


class Counter:
    def __init__(self, target: int, unlimited: bool):
        self._lock = threading.Lock()
        self._done = 0
        self._live = 0
        self._target = max(1, target)
        self._unlimited = unlimited
        self._finished = threading.Event()
        self._idle = threading.Event()
        self._idle.set()

    def entered(self) -> int:
        with self._lock:
            self._done += 1
            self._live += 1
            done = self._done
            full = not self._unlimited and done >= self._target
        self._idle.clear()
        if getattr(config, "Show_Counter", True):
            if self._unlimited:
                emit("Match %d" % done)
            else:
                emit("Match %d/%d" % (done, self._target))
        if full:
            self._finished.set()
        return done

    def left(self) -> None:
        with self._lock:
            self._live = max(0, self._live - 1)
            empty = self._live == 0
        if empty:
            self._idle.set()

    def finished(self) -> bool:
        return self._finished.is_set()

    def wait_idle(self, timeout: float) -> bool:
        return self._idle.wait(timeout)


COUNTER = Counter(config.HowManyMatch, not counted_run())


class Stream:
    def __init__(self):
        self._buffer = bytearray()

    def feed(self, data: bytes):
        self._buffer.extend(data)
        if len(self._buffer) > stream_cap():
            self._buffer.clear()
            return []
        frames = []
        while len(self._buffer) >= 5:
            length = int.from_bytes(self._buffer[1:5], "big")
            if length > frame_cap():
                self._buffer.clear()
                break
            total = 5 + length
            if len(self._buffer) < total:
                break
            frames.append(bytes(self._buffer[:total]))
            del self._buffer[:total]
        return frames


class Keepalive:
    def __init__(self, sock: socket.socket):
        self._sock = sock
        self._stop = threading.Event()
        self._payload = bytes.fromhex(ping_hex())
        self._beat()
        threading.Thread(target=self._loop, daemon=True).start()

    def _beat(self) -> bool:
        try:
            self._sock.sendall(self._payload)
            return True
        except Exception:
            return False

    def _loop(self) -> None:
        while not self._stop.wait(ping_interval()):
            if not self._beat():
                return

    def stop(self) -> None:
        self._stop.set()


class Lobby:
    def __init__(self, client: "SoloBot"):
        self._client = client
        self._sock: Optional[socket.socket] = None
        self._lock = threading.Lock()
        self._match = threading.Event()
        self._playing: Optional[threading.Event] = None
        self._lone_wolf = False
        self._watching = bool(getattr(config, "Show_Replies", True))
        self._seen = set()

    def connected(self) -> bool:
        return self._sock is not None

    def _send(self, payload: bytes) -> bool:
        with self._lock:
            sock = self._sock
            if sock is None:
                return False
            try:
                sock.sendall(payload)
                return True
            except Exception as exc:
                trace("send failed: %r" % exc)
                return False

    def run(self, handshake: str, key: bytes, iv: bytes,
            host: str, port: int) -> None:
        attempt = 0
        while True:
            try:
                self._session(handshake, key, iv, host, port)
                attempt = 0
            except Exception:
                pass
            attempt += 1
            time.sleep(min(2.0 * attempt, reconnect_cap()))

    def _session(self, handshake: str, key: bytes, iv: bytes,
                 host: str, port: int) -> None:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(socket_timeout())
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        try:
            sock.connect((host, port))
            sock.sendall(bytes.fromhex(handshake))
            sock.recv(4096)
        except Exception:
            sock.close()
            raise
        with self._lock:
            self._sock = sock
        alive = Keepalive(sock)
        try:
            self._read(key, iv)
        finally:
            alive.stop()
            with self._lock:
                self._sock = None
            try:
                sock.close()
            except Exception:
                pass

    def _read(self, key: bytes, iv: bytes) -> None:
        stream = Stream()
        while True:
            try:
                data = self._sock.recv(8192)
            except socket.timeout:
                continue
            except Exception:
                return
            if not data:
                return
            for packet in stream.feed(data):
                head = packet.hex()
                if head.startswith("0300") and len(packet) > 26:
                    try:
                        self._matchmaking(head)
                    except Exception:
                        pass

    def _matchmaking(self, head: str) -> None:
        decoded = tools.ProtobufDecoder.decode(bytes.fromhex(head[10:]))
        suss = decoded[5][0].value
        if not isinstance(suss, dict) or 2 not in suss or 3 not in suss:
            return
        server = entry_text(suss[2][0])
        secret = entry_text(suss[3][0])
        mask = entry_text(suss[42][0]) if 42 in suss else ""
        token = entry_text(suss[4][0]) if 4 in suss else ""
        if not (server and secret and mask and token):
            return
        details = {
            "server_addr": server,
            "secret": secret,
            "udp_jwt": token,
            "udp_mask": mask,
            "match_id": as_int(suss[1][0].value) if 1 in suss else 0,
            "sleep_ms": as_int(suss[5][0].value) if 5 in suss else 0,
            "map_id": as_int(suss[6][0].value, 1) if 6 in suss else 1,
            "game_mode": as_int(suss[7][0].value, 15) if 7 in suss else 15,
            "match_mode": as_int(suss[8][0].value, 1) if 8 in suss else 1,
        }
        self._playing = self._client.play(details)
        COUNTER.entered()
        self._match.set()

    def playing(self) -> Optional[threading.Event]:
        return self._playing

    def wait_match(self, timeout: float) -> bool:
        self._match.clear()
        self._playing = None
        return self._match.wait(timeout)

    def start_clash(self, key: bytes, iv: bytes) -> bool:
        try:
            payload = tools.clash_start_packet(
                key, iv, region=self._client.region())
        except Exception as exc:
            trace("clash packet build failed: %r" % exc)
            return False
        return self._send(payload)

    def start_lone_wolf(self, key: bytes, iv: bytes) -> bool:
        try:
            payload = tools.lone_wolf_packet(
                key, iv, region=self._client.region())
        except Exception as exc:
            trace("lone wolf packet build failed: %r" % exc)
            return False
        self._lone_wolf = True
        return self._send(payload)

    def stop(self, key: bytes, iv: bytes) -> bool:
        return self._send(tools.solo_stop_packet(key, iv))


class SoloBot:
    def __init__(self, uid: str, password: str, access_token: str = ""):
        self._uid = uid
        self._password = password
        self._access_token = access_token
        self._platform = ""
        self._account_id: Optional[int] = None
        self._client_host = fallback_client_host()
        self._token = ""
        # Profile state, read by whoever is supervising this run. Kept on the
        # instance rather than emitted, so a supervisor can sample it as often
        # as it likes without any of those reads costing an API call.
        self._profile: Dict[str, Any] = {}
        self._profile_at = 0.0
        self._xp_start: Optional[int] = None
        self._level_start: Optional[int] = None
        self._token_exp = 0

    def region(self) -> str:
        return config.LIVE.get_region()

    def country(self) -> str:
        return config.LIVE.country

    def anticheat_hosts(self) -> list:
        return config.LIVE.anticheat_hosts()

    def client_url(self, path: str) -> str:
        return "%s/%s" % (self._client_host, path.lstrip("/"))

    def run(self) -> bool:
        session = self._authenticate()
        if session is None:
            return False
        key, iv, handshake, game, lobby_addr = session
        lobby = Lobby(self)
        threading.Thread(target=lobby.run,
                         args=(handshake, key, iv, lobby_addr[0],
                               int(lobby_addr[1])), daemon=True).start()
        threading.Thread(target=self._game_link,
                         args=(handshake, game), daemon=True).start()
        self._loop(lobby, key, iv)
        return True

    def check_level(self) -> Optional[int]:
        show = self.profile()
        return show.get("level") if show else None

    def profile(self, force: bool = False) -> Dict[str, Any]:
        """Name, level, xp and region for this account.

        Rate limited. A supervisor wants a number every second; the server does
        not, and GetPlayerPersonalShow is a real request against a live
        service. So this runs at match pace - once before the run and once
        after each round - and anything reading more often gets the last answer
        out of memory instead of a new request.

        force still respects a floor. It means "a round just ended, a shorter
        wait is reasonable", not "ask again regardless".
        """
        gap = float(getattr(config, "Profile_Min_Gap", 25.0))
        if force:
            gap = min(gap, float(getattr(config, "Profile_Force_Gap", 5.0)))
        now = time.monotonic()
        if self._profile.get("xp") is not None and (now - self._profile_at) < gap:
            return self._profile
        if self._account_id is None:
            return self._profile

        url = self.client_url("GetPlayerPersonalShow")
        payload = tools.build_personal_show(self._account_id)
        try:
            response = requests.post(url,
                                     headers=api_headers(self._token,
                                                         host_of(url)),
                                     data=payload, verify=False,
                                     timeout=http_timeout())
        except Exception as exc:
            trace("personal show network error: %r" % exc)
            return self._profile
        if response.status_code != 200:
            # 401 here is the login running out, which is the expected end of a
            # long run rather than a fault. Saying which it was beats a silent
            # None that looks the same as a dropped connection.
            trace("personal show HTTP %d" % response.status_code)
            return self._profile

        show = tools.read_personal_show(response.content)
        if not show:
            trace("personal show could not be decoded")
            return self._profile

        self._profile_at = now
        for key, value in show.items():
            if value is not None:
                self._profile[key] = value

        # The first successful reading is the baseline the whole run is
        # measured against, so it is taken once and never moved. A supervisor
        # carrying a run across a login renewal seeds it beforehand, which is
        # why this only fills a value that is still None.
        if self._xp_start is None and show.get("xp") is not None:
            self._xp_start = int(show["xp"])
        if self._level_start is None and show.get("level") is not None:
            self._level_start = int(show["level"])
        self._profile["xp_start"] = self._xp_start
        self._profile["level_start"] = self._level_start
        self._profile["token_exp"] = self._token_exp

        if getattr(config, "Show_Counter", True):
            gained = None
            if self._xp_start is not None and show.get("xp") is not None:
                gained = int(show["xp"]) - self._xp_start
            emit("Profile %s  level %s  xp %s%s"
                 % (show.get("nickname") or "?",
                    show.get("level"),
                    show.get("xp"),
                    "" if gained is None else "  (+%d this run)" % gained))
        return self._profile

    def _loop(self, lobby: Lobby, key: bytes, iv: bytes) -> None:
        target = int(getattr(config, "LoneWolf_MinLevel", 3))
        ready = False
        # The baseline reading. Everything reported as "gained" is measured
        # from here, so it has to happen before the first match.
        self.profile(force=True)
        while not COUNTER.finished():
            if not lobby.connected():
                time.sleep(0.5)
                continue
            if not ready:
                level = self.check_level()
                ready = level is None or level >= target
            if ready:
                self._wolf_round(lobby, key, iv)
            else:
                self._clash_round(lobby, key, iv)
            # A round has just ended, so xp and level may actually have moved.
            # This is the only refresh during a run, and a round takes minutes,
            # which is what keeps the request rate at match pace rather than at
            # the rate anything happens to be watching.
            self.profile(force=True)
        COUNTER.wait_idle(config.Udp_Match_Timeout)
        # The closing reading, so "after" is the number once the last match's
        # xp has settled rather than the one from before it.
        time.sleep(float(getattr(config, "Xp_Settle_Delay", 8.0)))
        self._profile_at = 0.0
        self.profile(force=True)
        time.sleep(float(getattr(config, "Shutdown_Delay", 30.0)))
        os._exit(0)

    def _clash_round(self, lobby: Lobby, key: bytes, iv: bytes) -> None:
        if not lobby.start_clash(key, iv):
            time.sleep(1.0)
            return
        if not lobby.wait_match(config.Match_Wait):
            lobby.stop(key, iv)
            time.sleep(config.Retry_Delay + random.uniform(0.0, 3.0))
            return
        playing = lobby.playing()
        if playing is not None:
            playing.wait(config.Udp_Match_Timeout)
        lobby.stop(key, iv)
        settle = config.Xp_Settle_Delay
        if safe_mode():
            settle += safe_gap()
        time.sleep(settle)

    def _wolf_round(self, lobby: Lobby, key: bytes, iv: bytes) -> None:
        if not lobby.start_lone_wolf(key, iv):
            time.sleep(1.0)
            return
        if not lobby.wait_match(config.Match_Wait):
            lobby.stop(key, iv)
            time.sleep(config.Retry_Delay + random.uniform(0.0, 3.0))
            return
        if safe_mode():
            playing = lobby.playing()
            if playing is not None:
                playing.wait(config.Udp_Match_Timeout)
            lobby.stop(key, iv)
            time.sleep(safe_gap())
            return
        lobby.stop(key, iv)
        time.sleep(config.Restart_Delay + random.uniform(0.0, 2.0))

    def _authenticate(self):
        if auth_mode() == "access_token":
            access_token, open_id, platform = self._inspect_token()
            mode = "access_token"
        else:
            access_token, open_id = self._guest_token()
            platform = ""
            mode = "guest"
        if not access_token:
            trace("no access token")
            return None
        self._platform = platform
        if getattr(config, "Show_Login", False):
            self._report_auth(mode, open_id, platform)
        result = self._major_login(access_token, open_id, platform)
        if result is None:
            return None
        token, key, iv, stamp = result
        try:
            claims = jwt.decode(token, options={"verify_signature": False})
            self._account_id = int(claims["account_id"])
        except Exception as exc:
            trace("token decode failed: %r" % exc)
            return None

        # exp is when this login stops being accepted, and it is the real end
        # of a long run: every call after it returns 401 and the match count
        # silently stops moving. Reading it here lets the supervisor renew the
        # login before that happens instead of discovering it afterwards.
        try:
            self._token_exp = int(claims.get("exp") or 0)
        except Exception:
            self._token_exp = 0
        for claim in ("noti_region", "lock_region"):
            value = claims.get(claim)
            if value:
                self._profile.setdefault("region", str(value))
                break
        if claims.get("country_code"):
            self._profile["country"] = str(claims["country_code"])
        self._profile["token_exp"] = self._token_exp
        if getattr(config, "Show_Login", False) and self._token_exp:
            left = max(0, self._token_exp - int(time.time()))
            # time.gmtime rather than datetime.utcfromtimestamp: that one is
            # deprecated in 3.12 and removed later.
            emit("  token expires      %s  (in %dh %dm)"
                 % (time.strftime("%Y-%m-%d %H:%M:%S UTC",
                                  time.gmtime(self._token_exp)),
                    left // 3600, (left % 3600) // 60))

        self._token = token
        addresses = self._login_data(token, access_token, open_id, platform)
        if addresses is None:
            trace("GetLoginData failed")
            return None
        handshake = self._handshake(token, key, iv, stamp)
        if handshake is None:
            trace("handshake build failed")
            return None
        return key, iv, handshake, addresses[0], addresses[1]

    def _inspect_token(self):
        token = self._access_token
        if not token:
            trace("access_token.json has no token")
            return "", "", ""
        headers = {
            "Host": "100067.connect.garena.com",
            "User-Agent": "GarenaMSDK/4.0.19P4(G011A ;Android 9;en;US;)",
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept-Encoding": "gzip, deflate, br",
            "Connection": "close",
        }
        try:
            response = requests.get(token_inspect_url(token), headers=headers,
                                    timeout=http_timeout())
        except Exception as exc:
            trace("token inspect network error: %r" % exc)
            return "", "", ""
        if response.status_code != 200:
            # The real reason lives in the status and body: 403 for a bad or
            # region-blocked token, 401 for an expired one. Hiding it behind a
            # JSON decode error is what made a dead token look like a code bug.
            trace("token inspect HTTP %d: %s"
                  % (response.status_code, response.text[:200]))
            return "", "", ""
        try:
            data = response.json()
        except Exception:
            trace("token inspect not JSON: %s" % response.text[:200])
            return "", "", ""
        open_id = str(data.get("open_id", ""))
        platform = str(data.get("platform", ""))
        if not open_id:
            trace("token inspect returned no open_id: %s" % data)
            return "", "", ""
        return token, open_id, platform

    def _guest_token(self):
        body = {
            "uid": self._uid, "password": self._password,
            "response_type": "token", "client_type": "2",
            "client_secret": garena_secret(), "client_id": "100067",
        }
        try:
            response = requests.post(garena_token_url(),
                                     headers=garena_headers(), data=body,
                                     timeout=http_timeout())
            payload = response.json()
            return payload["access_token"], payload["open_id"]
        except Exception as exc:
            trace("guest token failed: %r" % exc)
            return None, None

    def _major_login(self, access_token: str, open_id: str, platform: str = ""):
        payload = build_login(open_id, access_token, {99: "4", 102: {}},
                              platform=platform)
        headers = api_headers(host=host_of(major_login_url()))
        headers["Content-Length"] = str(len(payload))
        try:
            response = requests.post(major_login_url(), headers=headers,
                                     data=payload, verify=False,
                                     timeout=http_timeout())
            if response.status_code != 200:
                trace("MajorLogin %d" % response.status_code)
                return None
            body = response.content
            try:
                stamp, key, iv = parse_handshake(body)
                parsed = json.loads(tools.get_available_room(body.hex()))
            except Exception:
                body = response.content[64:]
                stamp, key, iv = parse_handshake(body)
                parsed = json.loads(tools.get_available_room(body.hex()))
            token = parsed["8"]["data"]
        except Exception as exc:
            trace("MajorLogin failed: %r" % exc)
            return None
        served = parsed.get("10", {}).get("data")
        if served:
            self._client_host = normalise_host(served)
            toolsudp.set_base_url(self._client_host)
        config.LIVE.set_login(
            region=parsed.get("3", {}).get("data") or "",
            country=parsed.get("4", {}).get("data") or "",
            anticheat=parsed.get("16", {}).get("data") or "",
            client_host=self._client_host)
        config.LIVE.set_versions(client=autoupdate.remote_version(),
                                 release=autoupdate.release_version())
        if getattr(config, "Show_Login", False):
            self._report_login(parsed)
        return token, key, iv, stamp

    def _report_auth(self, mode: str, open_id: str, platform: str) -> None:
        emit("--- auth ---")
        emit("  mode               %s" % mode)
        if open_id:
            emit("  open_id            %s" % open_id)
        if mode == "access_token":
            if platform:
                emit("  platform           %s  (into fields 23/99/100)"
                     % platform)
            else:
                emit("  platform           MISSING from inspect")

    def _report_login(self, parsed: Dict[str, Any]) -> None:
        emit("--- MajorLogin ---")
        for number, label in (("3", "region"), ("4", "country"),
                              ("16", "anticheat")):
            raw = parsed.get(number, {}).get("data")
            if raw:
                emit("  field %-3s %-10s %s" % (number, label, raw))
            else:
                emit("  field %-3s %-10s MISSING" % (number, label))
        emit("  client host        %s" % self._client_host)
        emit("  client version     %s" % config.LIVE.client_version)
        emit("  release version    %s" % config.LIVE.release_version)
        emit("--- in use ---")
        emit("  region             %s" % config.LIVE.get_region())
        emit("  country            %r" % config.LIVE.country)
        hosts = config.LIVE.anticheat_hosts()
        emit("  anticheat hosts    %d" % len(hosts))
        for host in hosts:
            emit("      %s" % host)
        emit("------------------")

    def _login_data(self, token: str, access_token: str, open_id: str,
                    platform: str = ""):
        payload = build_login(open_id, access_token,
                              {99: "0", 102: {}, 103: 1}, platform=platform)
        url = self.client_url("GetLoginData")
        headers = api_headers(token, host_of(url))
        headers["Connection"] = "close"
        headers["Accept-Encoding"] = "gzip, deflate, br"
        for _ in range(3):
            try:
                response = requests.post(url, headers=headers, data=payload,
                                         verify=False, timeout=http_timeout())
                response.raise_for_status()
                parsed = json.loads(
                    tools.get_available_room(response.content.hex()))
                return (split_address(parsed["32"]["data"]),
                        split_address(parsed["14"]["data"]))
            except Exception:
                time.sleep(2.0)
        return None

    def _handshake(self, token: str, key: bytes, iv: bytes,
                   stamp: int) -> Optional[str]:
        try:
            encrypted = tools.encrypt_packet(token.encode().hex(), key, iv)
            account_hex = "%x" % self._account_id
            padding = "0" * (16 - len(account_hex))
            length = "%x" % (len(encrypted) // 2)
            return "0115%s%s%s00000%s%s" % (padding, account_hex,
                                            dec_to_hex(stamp), length,
                                            encrypted)
        except Exception as exc:
            trace("handshake: %r" % exc)
            return None

    def _game_link(self, handshake: str, game: Tuple[str, str]) -> None:
        attempt = 0
        while True:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(socket_timeout())
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
            sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
            alive = None
            try:
                sock.connect((game[0], int(game[1])))
                sock.sendall(bytes.fromhex(handshake))
                sock.recv(4096)
                alive = Keepalive(sock)
                attempt = 0
                while True:
                    try:
                        if not sock.recv(4096):
                            break
                    except socket.timeout:
                        continue
            except Exception:
                pass
            finally:
                if alive is not None:
                    alive.stop()
                try:
                    sock.close()
                except Exception:
                    pass
            attempt += 1
            time.sleep(min(2.0 * attempt, reconnect_cap()))

    def play(self, details: Dict[str, Any]) -> threading.Event:
        host, port = split_address(details["server_addr"])
        account_id = self._account_id
        token = self._token
        over = threading.Event()
        loud = bool(getattr(config, "Show_Match", False))

        def session() -> None:
            try:
                toolsudp.run_match(host, port, account_id, details["secret"],
                                   details["udp_jwt"], details["udp_mask"],
                                   suffix="0",
                                   game_mode=details["game_mode"],
                                   map_id=details["map_id"],
                                   match_mode=details["match_mode"],
                                   sleep_ms=details["sleep_ms"],
                                   api_token=token,
                                   match_id=details["match_id"],
                                   verbose=loud)
            except Exception:
                pass
            finally:
                COUNTER.left()
                over.set()

        threading.Thread(target=session, daemon=True).start()
        return over


def load_account() -> Optional[Dict[str, str]]:
    try:
        with open(account_file(), encoding="utf-8") as handle:
            return json.load(handle)
    except Exception:
        return None


def load_token() -> str:
    try:
        with open(token_file(), encoding="utf-8") as handle:
            return str(json.load(handle).get("token", ""))
    except Exception:
        return ""


def main() -> None:
    check_config_fresh()
    autoupdate.shared().start()
    mode = auth_mode()
    if getattr(config, "Show_Login", False):
        emit("auth mode: %s  (isGuest=%s IsAccessToken=%s)"
             % (mode or "none", use_guest(), use_access_token()))
    if mode == "access_token":
        token = load_token()
        if not token:
            trace("access_token.json missing or empty")
            return
        SoloBot("", "", access_token=token).run()
    elif mode == "guest":
        account = load_account()
        if not account:
            trace("account_id.json missing")
            return
        SoloBot(account["uid"], account["password"]).run()


if __name__ == "__main__":
    main()
