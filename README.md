# cPanel2OpenLiteSpeed
This script is used to migrate the application layer from one cPanel and Apache or OpenLiteSpeed server to another. 

- The script then checks for the existence of user accounts and groups on the cPanel server and creates them if they do not exist on the remote server.
- Next, it creates the directory for migration on the remote server using SSH and sets ownership. It transfers files using rsync and assigns ownership to the transferred files.
- It creates the vhost.conf file in OpenLiteSpeed based on the arguments in the transfer.conf file and creates a virtual host definition in the httpd.conf main configuration file with the cPanel username.
- It then checks for the existence of listeners in OpenLiteSpeed, and if listeners for port 443 or 8080 do not exist, it creates them. If listeners already exist, it adds mapping entries for virtual hosts to the listeners based on the main domain name.
- Next, it creates a Bash script on the remote server to fix any htaccess incompatibilities and updates the htaccess file by running the created script.
- If there are Cloudflare settings in the transfer.conf file, it updates the A records in Cloudflare with the target server's external IP address. Additionally, if Cloudflare settings are present, it changes the SSL mode to "FULL".
- Finally, it gracefully restarts OpenLiteSpeed on the remote server.
- The application migration process is successfully completed with these steps.



## Explanation of `transfer.conf` Arguments

The `transfer.conf` file contains the arguments used during the application migration process. Below are explanations of these arguments:

- `destinationUser`: **root** *#The SSH username of the remote server.*
- `destinationServer`: **10.100.100.10** *The address of the remote server*
- `destinationBasePath`:**/home/{cPanelUsername}** *The main directory where the migration will take place*
- `destinationFullPath`: **/home/{cPanelUsername}/public_html** *The directory where the site files will be moved to*
- `adminEmails`:**admin@webmaster.com** *Admin email address of the virtual host on the target server*
- `phpHandler`: **lsapi:lsphp74** *PHP handler information on the target server (e.g., "lsapi:lsphp74")*
- `lswsConfigPath`:**/usr/local/lsws/conf/httpd_config.conf** *Path to the OpenLiteSpeed configuration file (default: "/usr/local/lsws/conf/httpd_config.conf")*
- `vhostPath`:**/usr/local/lsws/conf/vhosts** *Directory where OpenLiteSpeed virtual hosts files are located (default: "/usr/local/lsws/conf/vhosts")*
- `vhostOwner`:**lsadm** *Owner of OpenLiteSpeed virtual host files (default: "lsadm")*
- `vhostGroup`:**nogroup** *Group of OpenLiteSpeed virtual host files (default: "nogroup")*
### Cloudflare Arguments

**Note**: If Cloudflare arguments are not defined, the following operations will not be performed.

- `cloudflareEmail`: Cloudflare email address (e.g., "info@gelistir.com.tr").
- `cloudflareAPIKey`: Cloudflare API key (e.g., "9d52f5fd08d770175ad11cca4f55f74fd12ba").
- `cloudflareADNSRecordValue`: A DNS record value for Cloudflare (e.g., "96.134.81.101" - target server address).

## How to Use the Application

To use the application, follow the steps below:

1. Run the following command:
   ```bash
   php transfer-tool.php
   ```
2. You will be prompted to enter the cPanel username:
   ```bash
   Please enter the cPanel username:
   ```
3. Enter the cPanel username and press Enter, for example:
   ```bash
   samplecpanelusername
   ```
4. The application will perform the following tasks and provide the corresponding output:
   ```bash
    - Destination Server User and group were checked. Created if necessary.
    - Files transferred to the server at 96.134.81.101//home/samplecpanelusername.
    - Destination Server Vhost file for samplecpanelusername has been created.
    - Destination Server Virtualhost configuration for samplecpanelusername has been added.
    - Destination Server Map entry for samplecpanelusername.com added to existing openlitespeed listener block for port 443.
    - Destination Server Map entry for samplecpanelusername.com added to existing openlitespeed listener block for port 8080.
    - Bash script created successfully on the destination server.
    - Htaccess file updated successfully on the destination server.
    - DNS record updated successfully on cloudflare.
    - SSL mod changed successfully [Full] on cloudflare.
    - Destination Server LiteSpeed server restarted gracefully.
    - Elapsed time: 10.46 seconds
   
   ```      
   
