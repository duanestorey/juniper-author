# Juniper/Author
A WordPress plugin to manage plugin and theme ownership on various platforms, starting with Github.

## How It Works

Juniper/Author is meant to be installed by plugin and theme authors who currently distribute or want to distribute their plugins and themes via Github. 

It synchonizes your Github repositories to your public WordPress installation, which can then be used to cryptographically sign your ZIPs for distribution (coming soon).  In addition, Juniper/Author provides a WordPress API endpoint for Juniper/Server, a distributed mirror system that does not rely on WordPress.org for finding and installing WordPress plugins and themes.

## Installation

Juniper/Author can be installed as normal by downloading the plugin from Github and installing it in the WordPress admin.  At some point in the near future, the Juniper/Berry installer will be complete which will allow only cryptographically signed ZIP files to be installed.  Why is that important?  When a ZIP file is signed and verified, it means it was generated by the author and not tampered with at any point.  This prevents supply-chain attacks where a rogue organization could potentially take over a plugin or theme supply chain, effectively taking ownership of it.

## Post-Install Steps

Once the plugin is installed, you'll need to perform the following steps:

1. In the admin panel in the Authorship/Options section, generate a private/public key pair to be used for signing.  Your private key needs to be encrypted with a password, so make sure to choose a strong one here during the key generation phase.  This password is not stored anywhere on your install, so if you lose it you will no longer be able to sign your ZIP files, and will be forced to regenerate a new key (effectively making all other previously released ZIP files no longer valid).
2. 
