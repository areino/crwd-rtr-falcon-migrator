#!/usr/bin/env python3
r"""rtr-migrate-to-sophos
   ____      _____    ____          __  __              ____     ____        _       _____   U  ___ u   ____     
U |  _"\ u  |_ " _|U |  _"\ u     U|' \/ '|u   ___   U /"___|uU |  _"\ u U  /"\  u  |_ " _|   \/"_ \/U |  _"\ u  
 \| |_) |/    | |   \| |_) |/     \| |\/| |/  |_"_|  \| |  _ / \| |_) |/  \/ _ \/     | |     | | | | \| |_) |/  
  |  _ <     /| |\   |  _ <        | |  | |    | |    | |_| |   |  _ <    / ___ \    /| |\.-,_| |_| |  |  _ <    
  |_| \_\   u |_|U   |_| \_\       |_|  |_|  U/| |\u   \____|   |_| \_\  /_/   \_\  u |_|U \_)-\___/   |_| \_\   
  //   \\_  _// \\_  //   \\_     <<,-,,-..-,_|___|_,-._)(|_    //   \\_  \\    >>  _// \\_     \\     //   \\_  
 (__)  (__)(__) (__)(__)  (__)     (./  \.)\_)-' '-(_/(__)__)  (__)  (__)(__)  (__)(__) (__)   (__)   (__)  (__) 
 
 Use RTR API to migrate endpoints from Falcon to Sophos across CID or host group

 CHANGE LOG

 10/10/2025   v1.0    First version

"""

# Import dependencies
import datetime
import os
from urllib.parse import urlparse
from argparse import ArgumentParser, RawTextHelpFormatter

version = "1.0"

# Define logging function
def log(msg):
    """Print the log message to the terminal."""
    print(datetime.datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S") + '  ' + str(msg))

# Import SDK
try:
    from falconpy import(
        Hosts,
        OAuth2,
        RealTimeResponse,
        RealTimeResponseAdmin,
        HostGroup,
        SensorDownload
    )
except ImportError as err:
    log(err)
    log("Python falconpy library is required.\n"
        "Install with: python3 -m pip install crowdstrike-falconpy"
        )
    raise SystemExit("Python falconpy library is required.\n"
                     "Install with: python3 -m pip install crowdstrike-falconpy"
                     ) from err

# Process command line arguments
parser = ArgumentParser(description=__doc__, formatter_class=RawTextHelpFormatter)
req = parser.add_argument_group("required arguments")

req.add_argument("--falcon_client_id",
                 help="CrowdStrike Falcon API Client ID",
                 required=True
                 )

req.add_argument("--falcon_client_secret",
                 help="CrowdStrike Falcon API Client Secret",
                 required=True
                 )

req.add_argument("--scope",
                 help="Which hosts to change, can be 'cid' or 'hostgroup'",
                 choices=['cid', 'hostgroup'],
                 required=True
                 )

req.add_argument("--scope_id",
                 help="CID or Host Group ID",
                 required=True
                 )

req.add_argument("-b", "--base_url",
                    help="CrowdStrike base URL (only required for GovCloud, pass usgov1)",
                    required=False,
                    default="auto"
                    )

opt = parser.add_argument_group("migration options")
opt.add_argument("--sophos_url",
                 help="Direct download URL to Sophos installer (e.g., SophosSetup.exe)",
                 required=True
                 )
opt.add_argument("--sophos_args",
                 help="Arguments to pass to Sophos installer",
                 required=False,
                 default="--quiet"
                 )
opt.add_argument("--download_dir",
                 help="Directory on endpoint to store installer (e.g., %TEMP%\\CSMigrate)",
                 required=False,
                 default="%TEMP%\\CSMigrate"
                 )
opt.add_argument("--uninstall_falcon",
                 help="Uninstall CrowdStrike Falcon after Sophos install",
                 action="store_true",
                 default=False
                 )
opt.add_argument("--falcon_maintenance_token",
                 help="Falcon maintenance token for uninstall (if protection enabled)",
                 required=False,
                 default=""
                 )

args = parser.parse_args()

if args.scope.lower() not in ["cid", "hostgroup"]:
    log("The scope needs to be 'cid' or 'hostgroup'")
    raise SystemExit("The scope needs to be 'cid' or 'hostgroup'")

falcon_admin = None  # will be set in main()


def execute_command(batch_id, command, timeout_seconds=600):
    """Execute a raw RTR script command across the batch with a timeout."""
    global falcon_admin
    response = falcon_admin.batch_admin_command(
        batch_id=batch_id,
        base_command="runscript",
        command_string=f"runscript -timeout={int(timeout_seconds)} -Raw=```{command}```"
    )
    if response.get("status_code") == 201:
        log(f"-- Launched command (timeout={timeout_seconds}s)")
    else:
        log(f"-- Failed to launch command: {response}")

  

# Main routine
def main():  
    log(f"Starting execution of script v{version}")

    log("Authenticating to API")
    auth = OAuth2(client_id=args.falcon_client_id,
                  client_secret=args.falcon_client_secret,
                  base_url=args.base_url
                  )

    # Check which CID the API client is operating in, as sanity check. Exit if operating CID does not match provided scope_id.
    falcon = SensorDownload(auth_object=auth, base_url=args.base_url)
    response = falcon.get_sensor_installer_ccid()

    if response["status_code"] < 300:
        log(f"-- Authentication correct.")
    else:
        log(f"-- Authentication error: {response['status_code']} - {response['body']['errors'][0]['message']}")
        raise SystemExit(f"-- Authentication error: {response['status_code']} - {response['body']['errors'][0]['message']}")

    current_cid = response["body"]["resources"][0][:-3]
    if (args.scope.lower() == "cid" and (args.scope_id.lower() != current_cid.lower())):
        log(f"The entered CID [{args.scope_id.upper()}] does not match the API client CID [{current_cid.upper()}].")
        raise SystemExit(f"The entered CID [{args.scope_id.upper()}] does not match the API client CID [{current_cid.upper()}].")



    # Fetch list of hosts
    if args.scope.lower() == "cid":
        log(f"Getting all hosts from CID [{args.scope_id}]")
        falcon = Hosts(auth_object=auth, base_url=args.base_url)
    else:
        log(f"Getting all hosts from host group ID [{args.scope_id}]")
        falcon = HostGroup(auth_object=auth, base_url=args.base_url)


    offset = ""
    hosts_all = []

    while True:
        batch_size = 5000 # 5000 is max supported by API

        if args.scope.lower() == "cid":
            # Fetch all Windows CID hosts
            response = falcon.query_devices_by_filter_scroll(offset=offset,
                                                             limit=batch_size,
                                                             filter="platform_name:'Windows'"
                                                             )
        else:
            # Fetch all Windows host group ID hosts
            if offset == "":
                response = falcon.query_group_members(limit=batch_size,
                                                      filter="platform_name:'Windows'",
                                                      id=args.scope_id
                                                      )
            else:
                response = falcon.query_group_members(offset=offset,
                                                      limit=batch_size,
                                                      filter="platform_name:'Windows'",
                                                      id=args.scope_id
                                                      )

        offset = response['body']['meta']['pagination']['offset']

        for host_id in response['body']['resources']:
            hosts_all.append(host_id)

        log(f"-- Fetched {len(response['body']['resources'])} hosts, "
            f"{len(hosts_all)}/{response['body']['meta']['pagination']['total']}"
            )

        if len(hosts_all) >= int(response['body']['meta']['pagination']['total']):
            break

    log(f"-- Retrieved a total of {str(len(hosts_all))} hosts")


    # Now that we have the host IDs, we create a batch RTR list of commands to execute it in all hosts

    falcon = RealTimeResponse(auth_object=auth, base_url=args.base_url)
    global falcon_admin
    falcon_admin = RealTimeResponseAdmin(auth_object=auth, base_url=args.base_url)
    

    # Get batch id

    response = falcon.batch_init_sessions(host_ids=hosts_all, queue_offline=True)
    batch_id = response['body']['batch_id']

    if batch_id:
        log(f"Initiated RTR batch with id {batch_id}")
    else:
        raise SystemExit("Unable to initiate RTR session with hosts.")


    # Commands to execute
    # 1) Download Sophos installer
    # 2) Install Sophos silently
    # 3) Optionally uninstall Falcon
    # 4) Cleanup

    installer_name = os.path.basename(urlparse(args.sophos_url).path) or "SophosSetup.exe"

    download_cmd = (
        "powershell -NoProfile -ExecutionPolicy Bypass -Command "
        "\"[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; "
        "$dl = if ('{dl}' -match '%TEMP%') {{ Join-Path $env:TEMP 'CSMigrate' }} else {{ '{dl}' }}; "
        "New-Item -ItemType Directory -Force -Path $dl | Out-Null; "
        "$u = '{url}'; $p = Join-Path $dl '{name}'; "
        "Invoke-WebRequest -UseBasicParsing -Uri $u -OutFile $p; if (-not (Test-Path $p)) {{ throw 'Download failed' }}\""
    ).format(dl=args.download_dir, url=args.sophos_url, name=installer_name)

    install_cmd = (
        "powershell -NoProfile -ExecutionPolicy Bypass -Command "
        "\"$dl = if ('{dl}' -match '%TEMP%') {{ Join-Path $env:TEMP 'CSMigrate' }} else {{ '{dl}' }}; "
        "$p = Join-Path $dl '{name}'; "
        "if (Test-Path $p) {{ Start-Process -FilePath $p -ArgumentList '{args}' -Wait; if ($LASTEXITCODE -ne 0) {{ exit $LASTEXITCODE }} }} else {{ Write-Error 'Installer not found'; exit 2 }}\""
    ).format(dl=args.download_dir, name=installer_name, args=args.sophos_args)

    uninstall_cmd = (
        "powershell -NoProfile -ExecutionPolicy Bypass -Command "
        "\"$token = '{token}'; "
        "$regPaths = @('HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall','HKLM:\\Software\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall'); "
        "$uninst = Get-ChildItem $regPaths | Get-ItemProperty | Where-Object { $_.DisplayName -like 'CrowdStrike*Sensor*' -or $_.DisplayName -like 'CrowdStrike Windows Sensor*' } | Select-Object -First 1; "
        "if ($null -ne $uninst) {{ $u = $uninst.UninstallString; $guid = $null; if ($u -match '{[0-9A-Fa-f-]+}') {{ $guid = $matches[0] }}; if ($null -ne $guid) {{ $msiArgs = '/X ' + $guid + ' /qn /norestart'; if ($token -ne '') {{ $msiArgs += ' MAINTENANCE_TOKEN=' + $token }}; Start-Process -FilePath 'msiexec.exe' -ArgumentList $msiArgs -Wait; if ($LASTEXITCODE -ne 0) {{ exit $LASTEXITCODE }} }} else {{ Start-Process -FilePath $u -ArgumentList '/quiet /norestart' -Wait; if ($LASTEXITCODE -ne 0) {{ exit $LASTEXITCODE }} }} }}\""
    ).format(token=args.falcon_maintenance_token)

    cleanup_cmd = (
        "powershell -NoProfile -ExecutionPolicy Bypass -Command "
        "\"$dl = if ('{dl}' -match '%TEMP%') {{ Join-Path $env:TEMP 'CSMigrate' }} else {{ '{dl}' }}; Remove-Item -LiteralPath $dl -Recurse -Force -ErrorAction SilentlyContinue\""
    ).format(dl=args.download_dir)

    execute_command(batch_id, download_cmd, timeout_seconds=900)
    execute_command(batch_id, install_cmd, timeout_seconds=3600)
    if args.uninstall_falcon:
        if not args.falcon_maintenance_token:
            log("-- Warning: Uninstall requested but no maintenance token provided; uninstall may fail if protection is enabled.")
        execute_command(batch_id, uninstall_cmd, timeout_seconds=900)
    execute_command(batch_id, cleanup_cmd, timeout_seconds=120)


    log("-- Finished launching RTR commands, please check progress in the RTR audit logs")
    log("End")

if __name__ == "__main__":
    main()
 
