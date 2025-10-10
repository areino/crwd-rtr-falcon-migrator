# A method to use CrowdStrike Falcon Real Time Response (RTR) capability to migrate from CrowdStrike to other endpoint solutions
 
## What is CrowdStrike Falcon RTR
 
CrowdStrike’s Real Time Response (RTR) is a remote, real-time investigation and remediation capability built into the Falcon platform. It allows security teams to open an interactive command-line session directly on an endpoint without disrupting the user or system operations. Through this channel, analysts can run forensic commands, inspect running processes, review file system contents, extract artifacts, and collect volatile data for deeper analysis. Crucially, it supports secure file transfers both ways, analysts can pull suspicious files back for analysis or push (and run) remediation scripts and tools onto the endpoint. From a response perspective, RTR provides a controlled environment to execute remediation steps immediately: killing malicious processes, deleting or quarantining files, clearing persistence mechanisms, or running custom scripts to harden the host.
 
## Falcon RTR API
 
You can invoke Real Time Response (RTR) completely via API (no manual use of the UI) by leveraging the FalconPy library or direct HTTP calls to the Falcon RTR endpoints. For example, using FalconPy you instantiate a RealTimeResponse client with your API credentials, then call methods like init_session, execute_command, get_extracted_file_contents, delete_file, etc.
/entities/active-responder-command/v1 (among others) to retrieve command statuses or results.
 
In effect, using the API you can spin up an RTR session against a host (or multiple hosts), send forensic or cleanup commands, retrieve outputs or transferred files, manage session lifetimes, and tear down the session, all in code and without touching the Falcon console.
 
CrowdStrike Real Time Response (RTR) also supports batch sessions, allowing security teams to issue commands, run scripts, or push files to multiple endpoints simultaneously. Instead of opening individual interactive sessions, the batch API endpoints let you fan out a single action across a fleet of hosts, streamlining large-scale investigations and remediation. Importantly, RTR maintains a command cache for offline systems—if a host is unreachable at the time of execution, the queued RTR instructions are held and automatically executed once the endpoint checks back in. This ensures consistency across distributed environments, reduces manual follow-up, and makes it practical to enforce remediation or run forensic sweeps at scale without being limited by endpoint availability.
 
## Using RTR for Migration
 
A migration from CrowdStrike Falcon to another endpoint solution can be orchestrated directly through Falcon’s own Real Time Response (RTR) functionality. Using RTR batch commands, the security team would first push the new endpoint agent installer to all targeted endpoints by downloading it from a trusted source. Once transferred, RTR can be used to silently launch the installation process across the environment, ensuring consistent deployment. After verifying the new agent is active, RTR commands can then initiate the uninstallation of the Falcon sensor, cleanly removing it from each host. This approach leverages Falcon’s own endpoint control to automate the transition at scale, minimizing downtime and reducing the need for manual intervention on each machine.
 
## High Level Process
 
Here’s a structured outline of the preparation process for migrating from CrowdStrike Falcon to a new endpoint agent using Falcon’s own Real Time Response (RTR):
 
1.	Disable Falcon Tamper Protection
   
    - Before any automated uninstallation can occur, Falcon’s tamper protection must be disabled through the Falcon console. This ensures RTR commands can successfully uninstall the Falcon sensor once the new solution has been deployed.
    - Alternatively a maintenance token can be created and used in the commands executed to remove Falcon.

2.	Create API Client Credentials

    - Generate an API Client ID and Secret in the Falcon console with appropriate RTR permissions. These credentials will be used by the migration script to authenticate and execute RTR batch commands programmatically.

3.	Build or Adapt the Migration Script

    - Leverage existing Falcon RTR automation scripts (e.g., https://github.com/areino/crwd-proxytool or https://github.com/areino/crwd-pushhosts) as templates.
    - Modify the commands executed so the script:
  	
        1.	Check pre-requisites (available disk space, memory, supported OS, etc.) before going ahead.
        2.	Downloads the new endpoint solution installer to each endpoint.
        3.	Executes the installer silently.
        4.	Runs the uninstall process for the CrowdStrike Falcon sensor.
  	
4.	Test in a Limited Scope

    - Use Falcon host groups to target a small, representative subset of systems.
    - Validate that commands execute successfully, the new agent comes online, and Falcon is properly uninstalled.

5.	Full Rollout Across the CID (tenant)

    - After successful limited testing, expand the scope to the full CID (Customer ID) to apply the migration process across all endpoints.
    - Monitor script logs, RTR command outputs, and endpoint status to confirm success and handle any exceptions.
 
 

