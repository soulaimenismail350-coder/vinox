import hashlib
import hmac
import json
import os
import random
import string
import sys
import time
from datetime import datetime
from typing import Any, Dict, Optional, Tuple

import requests
import urllib3

_HERE = os.path.dirname(os.path.abspath(__file__))
if _HERE not in sys.path:
    sys.path.insert(0, _HERE)

import config
import tools

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)


def garena_host() -> str:
    return "https://100067.connect.garena.com"


def garena_client_secret() -> str:
    return "2ee44819e9b4598845141067b281621874d0d5d7af9d8f7e00c1e54715b7d1e3"


def login_host() -> str:
    return tools.live_server_url()


def release_version() -> str:
    return tools.live_release_version()


def client_version() -> str:
    return tools.live_client_version()


def unity_version() -> str:
    return "2022.3.47f1"


def user_agent() -> str:
    return ("UnityPlayer/%s (UnityWebRequest/1.0, libcurl/8.5.0-DEV)"
            % unity_version())


def register_language() -> str:
    return "en"


def accounts_file() -> str:
    return os.path.join(_HERE, "account_id.json")


def made_file() -> str:
    return os.path.join(_HERE, "made_accounts.json")


def preset_avatar() -> int:
    return 102000007


def preset_outfits() -> Tuple[int, ...]:
    return (706, 5506, 4806, 4106)


def preset_count() -> int:
    return 2


def active_preset() -> int:
    return 1


def http_timeout() -> float:
    return 20.0


def host_of(url: str) -> str:
    return url.split("//", 1)[-1].split("/", 1)[0]


def random_letters(count: int) -> str:
    return "".join(random.choice(string.ascii_lowercase) for _ in range(count))


def random_device() -> str:
    return "".join(random.choice(string.ascii_uppercase + string.digits)
                   for _ in range(5))


def garena_user_agent() -> str:
    return "GarenaMSDK/4.0.39(%s ;Android %d.%d;%s;%s;)" % (
        random_device(), random.randint(8, 13), random.randint(0, 5),
        random_letters(2), random_letters(2))


def generate_password() -> str:
    seed = "%s%d%d" % ("".join(random.choice(string.ascii_letters)
                               for _ in range(8)),
                       random.randint(1000, 9999), int(time.time() * 1000))
    return hashlib.sha256(seed.encode("utf-8")).hexdigest().upper()


def generate_nickname() -> str:
    hex_time = hex(int(time.time() * 1000))[2:]
    name = ""
    for char in hex_time:
        mixed = chr(ord(char) ^ ord(random.choice(string.ascii_lowercase)))
        name += mixed if mixed.isalnum() else random.choice(string.ascii_lowercase)
    while len(name) < 12:
        name += random.choice(string.ascii_letters + string.digits)
    return name[:12]


def encode_string(original: str) -> str:
    keystream = bytes([
        0x30, 0x30, 0x30, 0x32, 0x30, 0x31, 0x37, 0x30,
        0x30, 0x30, 0x30, 0x30, 0x32, 0x30, 0x31, 0x37,
        0x30, 0x30, 0x30, 0x30, 0x30, 0x32, 0x30, 0x31,
        0x37, 0x30, 0x30, 0x30, 0x30, 0x30, 0x32, 0x30,
    ])
    return "".join(chr(ord(c) ^ keystream[i % len(keystream)])
                   for i, c in enumerate(original))


def api_headers(token: str = "", host: Optional[str] = None) -> Dict[str, str]:
    headers = {
        "Accept": "*/*",
        "Accept-Encoding": "deflate, gzip",
        "Authorization": "Bearer %s" % token if token else "Bearer",
        "X-Unity-Version": unity_version(),
        "X-GA": "v1 1",
        "X-GA-SV": "1789535859",
        "ReleaseVersion": release_version(),
        "Content-Type": "application/x-www-form-urlencoded",
        "User-Agent": user_agent(),
    }
    if host:
        headers["Host"] = host
    return headers


def garena_headers(signature: Optional[str] = None) -> Dict[str, str]:
    headers = {
        "User-Agent": garena_user_agent(),
        "Accept": "application/json",
        "Content-Type": "application/json; charset=utf-8",
        "Accept-Encoding": "gzip",
        "Connection": "Keep-Alive",
        "Host": "100067.connect.garena.com",
    }
    if signature:
        headers["Authorization"] = "Signature " + signature
    return headers


def login_common_fields() -> Dict[int, Any]:
    return {
        3: None, 4: "free fire", 7: client_version(), 8: "iOS 17.0.2",
        9: "Handheld", 10: "--", 11: "WIFI", 12: 1623, 13: 750, 14: "326",
        15: "arm64e | 0 | 6", 16: 2834, 17: "Apple A12 GPU", 18: "Metal",
        19: None, 20: "105.154.86.59", 21: "zb", 22: None, 23: "4",
        24: "Handheld", 25: "iPhone11,8", 29: None, 30: 1, 62: 122007,
        63: 2373, 70: 2, 73: 2, 76: 1, 77: "IOSDevice", 78: 3, 79: 1,
        83: "2019118044", 85: 3, 86: "Metal", 87: 511, 88: 4,
        90: "Agadir", 91: "09", 92: 3663, 93: "ios",
        94: "KqsHT1ISLB4g52xG4K5Li1B6QMSv/Z1pnF6YOaKb5Iy/WBFzI6ovYKJ8OILlgQri/OUIEA==",
        97: 1, 100: "4",
    }


def build_login(open_id: str, access_token: str,
                extra: Dict[int, Any]) -> bytes:
    fields = login_common_fields()
    fields[3] = str(datetime.now())[:-7]
    fields[19] = "Apple|%s" % tools.random_uuid().upper()
    fields[22] = open_id
    fields[29] = access_token
    fields.update(extra)
    return tools.encode_payload(fields)


def reason(response: Any) -> str:
    try:
        text = response.text.strip()
    except Exception:
        text = ""
    if not text:
        try:
            text = "".join(chr(b) if 32 <= b < 127 else "."
                           for b in response.content[:80])
        except Exception:
            text = ""
    return "%d %s" % (response.status_code, text[:90])


def guest_register() -> Tuple[Optional[str], str]:
    password = generate_password()
    body = json.dumps({"app_id": 100067, "client_type": 2,
                       "password": password, "source": 2})
    signature = hmac.new(garena_client_secret().encode("utf-8"),
                         body.encode("utf-8"), hashlib.sha256).hexdigest()
    try:
        response = requests.post(garena_host() + "/api/v2/oauth/guest:register",
                                 headers=garena_headers(signature), data=body,
                                 timeout=http_timeout())
    except Exception as exc:
        print("    network: %r" % exc)
        return None, password
    try:
        data = response.json()
    except Exception:
        print("    %s" % reason(response))
        return None, password
    if data.get("code") != 0:
        print("    garena: %s" % data)
        return None, password
    return data.get("data", {}).get("uid"), password


def guest_token(uid: str, password: str) -> Tuple[Optional[str], Optional[str]]:
    body = json.dumps({
        "client_id": 100067, "client_secret": garena_client_secret(),
        "client_type": 2, "password": password,
        "response_type": "token", "uid": uid,
    })
    try:
        response = requests.post(
            garena_host() + "/api/v2/oauth/guest/token:grant",
            headers=garena_headers(), data=body, timeout=http_timeout())
        data = response.json()
    except Exception as exc:
        print("    network: %r" % exc)
        return None, None
    if data.get("code") != 0:
        print("    garena: %s" % data)
        return None, None
    info = data.get("data", {})
    return info.get("access_token"), info.get("open_id")


def register_account(open_id: str, access_token: str) -> bool:
    payload = tools.encode_payload({
        1: generate_nickname(), 2: access_token, 3: open_id,
        5: preset_avatar(), 6: 4, 7: 1, 13: 1,
        14: encode_string(open_id), 15: register_language(), 16: 1,
        20: client_version(), 21: 1,
    })
    try:
        response = requests.post(login_host() + "/MajorRegister",
                                 headers=api_headers(), data=payload,
                                 verify=False, timeout=http_timeout())
    except Exception as exc:
        print("    network: %r" % exc)
        return False
    if response.status_code == 200:
        return True
    print("    %s" % reason(response))
    return False


def major_login(open_id: str, access_token: str):
    payload = build_login(open_id, access_token, {99: "4", 102: {}})
    try:
        response = requests.post(login_host() + "/MajorLogin",
                                 headers=api_headers(host=host_of(login_host())),
                                 data=payload, verify=False,
                                 timeout=http_timeout())
    except Exception as exc:
        print("    network: %r" % exc)
        return None, None, payload
    if response.status_code != 200:
        print("    %s" % reason(response))
        return None, None, payload
    try:
        body = response.content
        try:
            decoded = tools.ProtobufDecoder.decode(body)
        except Exception:
            decoded = tools.ProtobufDecoder.decode(body[64:])
    except Exception as exc:
        print("    decode: %r" % exc)
        return None, None, payload
    return (tools.field_text(decoded, 8), tools.field_text(decoded, 10),
            payload)


def choose_region(region: str, token: str) -> bool:
    try:
        response = requests.post(login_host() + "/ChooseRegion",
                                 headers=api_headers(token),
                                 data=tools.encode_payload({1: str(region)}),
                                 verify=False, timeout=http_timeout())
    except Exception as exc:
        print("    network: %r" % exc)
        return False
    if response.status_code == 200:
        return True
    print("    %s" % reason(response))
    return False


def activate_account(token: str, url: str, payload: bytes) -> bool:
    try:
        response = requests.post(url + "/GetLoginData",
                                 headers=api_headers(token, host_of(url)),
                                 data=payload, verify=False,
                                 timeout=http_timeout())
    except Exception as exc:
        print("    network: %r" % exc)
        return False
    if response.status_code == 200:
        return True
    print("    %s" % reason(response))
    return False


def newbie_role() -> int:
    return 3


def give_newbie_roles(token: str, url: str) -> bool:
    payload = tools.encode_payload({1: newbie_role()})
    try:
        response = requests.post(url + "/GiveAccountNewbieRoles",
                                 headers=api_headers(token, host_of(url)),
                                 data=payload, verify=False,
                                 timeout=http_timeout())
    except Exception as exc:
        print("    network: %r" % exc)
        return False
    if 200 <= response.status_code < 300:
        return True
    print("    %s" % reason(response))
    return False


def build_preset_loadout() -> bytes:
    outfits = preset_outfits()
    slots = [{2: outfits[0]}]
    for index in range(1, len(outfits)):
        slots.append({1: index, 2: outfits[index]})
    presets = []
    for number in range(1, preset_count() + 1):
        preset = {
            1: number,
            2: preset_avatar(),
            3: slots,
            6: "PRESET %d" % number,
        }
        if number == active_preset():
            preset[7] = 1
        presets.append(preset)
    return tools.encode_payload({6: {1: presets}, 11: 1})


def select_preset_loadout(token: str, url: str) -> bool:
    payload = build_preset_loadout()
    try:
        response = requests.post(url + "/SelectPresetLoadout",
                                 headers=api_headers(token, host_of(url)),
                                 data=payload, verify=False,
                                 timeout=http_timeout())
    except Exception as exc:
        print("    network: %r" % exc)
        return False
    if 200 <= response.status_code < 300:
        return True
    print("    %s" % reason(response))
    return False


def save_account(uid: str, password: str) -> None:
    with open(accounts_file(), "w", encoding="utf-8") as handle:
        json.dump({"uid": uid, "password": password}, handle, indent=2)
    made = []
    if os.path.exists(made_file()):
        try:
            with open(made_file(), encoding="utf-8") as handle:
                made = json.load(handle)
        except Exception:
            made = []
    made.append({"uid": uid, "password": password,
                 "created": datetime.now().isoformat(timespec="seconds")})
    with open(made_file(), "w", encoding="utf-8") as handle:
        json.dump(made, handle, indent=2)


def make_account(region: str = "ME") -> Tuple[Optional[str], Optional[str]]:
    uid, password = guest_register()
    if not uid:
        print("step 1 guest:register failed")
        return None, None
    print("step 1 uid %s" % uid)

    access_token, open_id = guest_token(uid, password)
    if not access_token or not open_id:
        print("step 2 token:grant failed")
        return None, None
    print("step 2 token ok")

    if not register_account(open_id, access_token):
        print("step 3 MajorRegister failed")
        return None, None
    print("step 3 registered")

    token, _, _ = major_login(open_id, access_token)
    print(access_token)
    if not token:
        print("step 4 MajorLogin failed")
        return None, None
    print("step 4 logged in")

    if not choose_region(region, token):
        print("step 5 ChooseRegion failed")
        return None, None
    print("step 5 region %s" % region)

    token, url, payload = major_login(open_id, access_token)
    if not (token and url):
        print("step 6 second MajorLogin failed (url=%r)" % url)
        return None, None
    url = url.rstrip("/")
    print("step 6 client %s" % url)

    if not activate_account(token, url, payload):
        print("step 7 GetLoginData failed")
        return None, None
    print("step 7 activated")

    if give_newbie_roles(token, url):
        print("step 8 newbie roles given")
    else:
        print("step 8 newbie roles refused")

    if select_preset_loadout(token, url):
        print("step 9 characters activated")
    else:
        print("step 9 loadout refused")
    save_account(uid, password)
    return uid, password


def main() -> None:
    count = int(sys.argv[1]) if len(sys.argv) > 1 else 1
    made = 0
    for _ in range(count):
        uid, password = make_account(getattr(config, "Region", "ME"))
        if uid:
            made += 1
            print("Account created")
            print(uid)
            print(password)
        else:
            print("Account creation failed")
    if count > 1:
        print("%d / %d created" % (made, count))


if __name__ == "__main__":
    main()
