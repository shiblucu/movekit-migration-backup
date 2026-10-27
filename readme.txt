=== MoveKit – Site Migration & Backup ===
Contributors: shiblucu
Tags: migration, backup, restore, clone, move
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move or back up WordPress sites of any size between LocalWP, Docker or MAMP and a live host. Push, pull, or use one backup file. No size limits.

== Description ==

MoveKit copies a complete WordPress site, database and wp-content, into one backup file that you can download, upload to another site and restore. It is made for moving sites between a local development setup (LocalWP, Docker, MAMP, XAMPP, WAMP and similar) and a live server, in either direction.

There is no size limit and nothing to buy: every feature is in this plugin.

= Push and pull =

Connect two sites once, then copy one over the other with a single click, without downloading or uploading files yourself.

* The live site shows a connection key. Paste it into MoveKit on your local site to connect them.
* Push replaces the live site with your local site. Pull replaces your local site with the live one.
* Only files that changed are sent. Files that are already the same on both sites are left out, so after the first transfer a push or pull of a multi-GB site takes a fraction of the time. Untick "Send only files that changed" to send everything.
* The local site always starts the transfer, so it works from behind a home router or a local-only address.
* Every request is signed with the key, which itself never travels over the network. Signed requests expire after ten minutes and cannot be replayed.
* Transfers go in pieces. If the connection drops, MoveKit keeps trying for 5 minutes with a countdown, then carries on from where it stopped.
* If the connection stays down, the transfer pauses. Resume it later, or click Clean up to remove its unfinished files and database tables from both sites.
* The site being replaced keeps running unchanged until the very end: the new database is built in temporary tables, new files wait in a holding folder, and both are switched in together.

= Skip your media library on local sites =

Pull without Media and, if you tick "Show missing media from the other site" first, MoveKit shows the live site's images on your local site, straight from the live site. It is off unless you tick it. Nothing is downloaded, so a 20 GB uploads folder never has to be copied just to develop a theme. Missing files only: anything that exists locally is used as before. Stop it any time from the notice at the top of the MoveKit screen.

= Move only what you need =

Choose what to include before a backup, push or pull: the database, media, plugins, themes, and other wp-content files. For example, pull just the live database to your local site, or push only your theme. A restore replaces only what the backup includes, and if the database is left out, everyone stays logged in.

Under Skip files, list files and folders to leave out, such as `*.zip` or `uploads/2019`. Caches, other backup plugins' archives, `.git` and `node_modules` folders are always left out.

= Built for reliability =

* Every piece of a backup carries its own checksum. The whole file is verified before a restore changes anything, so a damaged or cut-off backup is refused instead of half-restored.
* Before a restore starts, MoveKit checks the free disk space and compares the PHP and WordPress versions of both sites.
* The restored database is built in temporary tables and restored files wait in a holding folder, so the site keeps running unchanged while the restore works. Both are switched in at the end. If putting the files in place fails, the replaced files are put back.
* Undo restore: for a day after a restore, push or pull, the replaced database and files are kept, and one click puts the site back as it was.
* Only one restore, push or pull runs at a time, so two can never overwrite each other.
* Work happens in short steps, so large sites never hit PHP time limits, and an interrupted backup or restore carries on where it stopped.
* Uploads are sent in pieces, so the server's upload size limit does not matter.

= Moves sites properly =

* Replaces the old site address and server paths everywhere in the database, including inside serialized and JSON data, without breaking it. Only whole addresses are replaced, so `example.com` never changes `example.com.au`.
* Clears page builder CSS (Elementor, Divi, Beaver Builder) and page caches (WP Rocket, LiteSpeed, W3 Total Cache and others) after a restore, so the site doesn't show old addresses.
* A live site that allows search engines keeps allowing them, even when the copy came from a local site that discourages them.
* Handles different table prefixes, and database collation differences between MySQL 8 and MariaDB.
* Keeps the destination's wp-config.php and WordPress core files.

= Also available from the command line =

* `wp movekit export` creates a backup (add `--only=database,media` to include only some parts, and `--exclude="*.zip,uploads/2019"` to skip files; push and pull take both too)
* `wp movekit import <file>` restores one
* `wp movekit undo` undoes the last restore, push or pull into this site (`--discard` keeps it and frees the space)
* `wp movekit verify <file>` checks a backup without restoring it
* `wp movekit list` lists backups
* `wp movekit key` shows this site's connection key (`--regenerate` makes a new one)
* `wp movekit connect <key>` connects another site, and `wp movekit sites` lists connected sites
* `wp movekit push <site>` and `wp movekit pull <site>` copy this site to a connected site, or the connected site to this one (`--all-files` sends files that are already the same too)

== Installation ==

1. Install MoveKit on both sites: the one you are copying from and the one you are copying to.
2. On the source site, open MoveKit in the admin menu and click Create backup, then Download.
3. On the destination site, open MoveKit, click Choose file and upload the backup.
4. Click Restore. When it finishes, log in with the username and password from the source site.

To push and pull instead:

1. Install MoveKit on both sites.
2. On the live site, open MoveKit and copy the key under This site's connection key.
3. On the local site, open MoveKit, paste it under Connect a site and click Connect.
4. Click Push or Pull next to the connected site.

== Frequently Asked Questions ==

= What is not included in a backup? =

wp-config.php and WordPress core files, which the destination keeps. Also cache and upgrade folders, the object-cache.php and advanced-cache.php drop-ins, other backup plugins' archive folders, `.git` and `node_modules` folders, must-use plugins and files that hosts add themselves (such as WP Engine's mysql.sql and Kinsta's must-use plugin), and MoveKit itself. You can add your own under Skip files.

= Is there a size limit or a paid version? =

No. MoveKit works in short steps and sends files in pieces, so sites of many gigabytes move the same way as small ones. There is no paid version and no feature is held back.

= Can I undo a restore? =

Yes, for a day. After a restore or a pull, the MoveKit screen shows Undo restore, which puts back the database and files the site had before. After a push, the Undo button is on the MoveKit screen of the site you pushed to. Click Keep the restore to free the space sooner. From the command line, use `wp movekit undo`.

= Does a push or pull leave files behind? =

The temporary backups are deleted from both sites as soon as a push or pull finishes. The site that was replaced keeps what it replaced for a day, so the transfer can be undone, and MoveKit then deletes it on its own, along with a few small progress records. Click Keep the restore to free the space sooner. Only backups you create yourself stay until you delete them.

= Does MoveKit make scheduled backups? =

Not yet. Backups are made when you click Create backup or run `wp movekit export`, which you can schedule with your host's cron jobs.

= Where are backups stored? =

In wp-content/movekit-backups. A folder of its own keeps backups, connection settings and unfinished transfers out of the media library and out of the database, so a restore never overwrites them and they are easy to find and delete. The folder is protected from direct web access on Apache and IIS, and backup file names contain a random part so they cannot be guessed on nginx.

= Which tables and data are included? =

Every table that starts with this site's table prefix, including the tables of plugins such as WooCommerce, Yoast SEO, WPML and Elementor, and their serialized and JSON data. Tables of another WordPress site in the same database are left out. Tables that exist only on the site being replaced are kept, not deleted. Database views, triggers and stored routines are not included.

= Does it support multisite? =

Not yet. Single-site installs only.

= What happens to my backups if I delete the plugin? =

They are kept. To delete them along with the plugin, add `define( 'MOVEKIT_DELETE_BACKUPS_ON_UNINSTALL', true );` to wp-config.php before deleting it.

= Does MoveKit send data anywhere? =

Only to sites you connect yourself. Push and pull talk directly to the other site whose key you pasted; nothing goes to any third-party service, MoveKit collects no user data, and it makes no other external requests. The one exception is a choice you make: if you tick "Show missing media from the other site" on a pull, your visitors' browsers load missing images from that site's uploads folder until you click Stop.

= Where is the source code? =

Everything in the plugin is readable source with no build step. The development repository is at https://github.com/shiblucu/movekit-migration-backup, where you can also report issues.

= Is the connection key safe to share? =

Treat it like a password: anyone with the key can replace or copy the site. It is stored in a protected file in the backups folder, not in the database. If it leaks, click Regenerate key, then paste the new key into the sites you connect from.

= Push or pull says the connection key was not accepted =

The message ends with the reason. "signature mismatch" means the key was regenerated on the other site, or MoveKit was reinstalled there: copy the current key from the other site and connect again. "request received twice" means a cache in front of the other site answered with an earlier reply; update MoveKit on both sites to 0.8.0 or later, which gives every request its own address. If the message says the signature was removed, ask the host to let requests to /wp-json/movekit/ through unchanged.

= Connecting says a firewall blocked the request =

A firewall in front of the other site refused MoveKit's request before WordPress saw it. On Hostinger, open Websites, then Dashboard, Performance, CDN, Manage, and on the Security tab set the security level to Medium or Low, or disable the CDN while you transfer. On other hosts, allow requests to /wp-json/movekit/ in the firewall or security plugin.

= What happens if my internet drops in the middle of a push or pull? =

MoveKit keeps trying for 5 minutes and shows a countdown. If the connection comes back, the transfer continues from where it stopped. If not, it pauses, and nothing on the site being replaced has been changed yet. The MoveKit screen then offers Resume and Clean up, even after a reload. Clean up deletes the unfinished backup files and temporary database tables on both sites. From the command line, use `wp movekit resume <job>` or `wp movekit discard <job>`.

= How does MoveKit show media without copying it? =

After a pull that leaves out Media, MoveKit remembers the other site's uploads address. When a page asks for an uploaded file that is not on this site, the address in the page is changed to point at the other site. Files that exist locally are never changed, and nothing is written to disk. It only affects pages, not the files on disk, so a backup or push of this site still contains only what is really here.

= Is it safe to restore a backup someone sent me? =

Only restore backups and connect to sites you trust. A backup can contain code (plugins, themes) and database content, and restoring it replaces both, just as installing a plugin from that person would. The same goes for a connection key: anyone who has it can replace or copy your site, so share it only with yourself.

= How does MoveKit know which files changed? =

Before a push or pull, the receiving site sends a short fingerprint of each file it has, made from the file's path, size and modification date. The sending site leaves out every file whose fingerprint matches. MoveKit keeps the original modification date on every file it copies, so a file changes its fingerprint only when it is edited on one of the sites. Files that exist only on the receiving site are left alone, as before. The database is always sent in full.

= How much free disk space do I need? =

A restore needs room for the backup file plus a second copy of the files being restored, because new files wait in a holding folder until the switch-over. The replaced database tables are also kept for a day so the restore can be undone. Plan for free space of about twice the size of what you move. MoveKit checks this before it starts and tells you if there isn't enough.

= Why does connecting say the other site does not use https? =

Push and pull carry your whole site, so MoveKit only connects to a live site over https, where nobody on the network can read or change the transfer. Turn on SSL for that site (most hosts offer a free certificate), then copy its connection key again. Local development sites, such as .local, .test, localhost and private network addresses, may use plain http.

= Can I push to a site using a self-signed certificate? =

MoveKit verifies HTTPS certificates. For local sites with self-signed certificates, add `add_filter( 'movekit_sslverify', '__return_false' );` on the site that starts the transfer.

== Screenshots ==

1. Back up the site, upload a backup, and choose what to include.
2. Connect a live site with its key, then push or pull with one click.
3. Progress while pushing a site to another server.
4. A transfer paused by a lost connection can be resumed or cleaned up.
5. A finished push, with a link to the other site and how to undo it.

== Changelog ==

= 0.10.3 =
* Fixed restores on hosts that refuse to create files such as `index.php` in MoveKit's holding folder (seen on Hostinger). Restored files are now held under neutral names until they are moved into place, and a failed write now says why.

= 0.10.2 =
* "Show missing media from the other site" is now off unless you tick it, because it makes visitors' browsers load images from the other site. The privacy answer and a link to the source code are in the readme.

= 0.10.1 =
* Fixed MySQL and MariaDB BIT columns, which were copied with a wrong value.
* Tables of another WordPress site in the same database (for example a `wp_shop_` site next to `wp_`) are no longer included in a backup of the `wp_` site.
* A row bigger than the database's `max_allowed_packet` now says so and tells you what to raise it to.
* Leftover temporary tables and old copies are removed automatically when no restore is running and nothing can be undone.

= 0.10.0 =
* Pull without Media and, if you choose to, the local site shows missing images from the live site instead of copying them. WP-CLI: add `--remote-media` to a pull.
* Security: backups are now saved readable only by the web server account, and their file names and the undo folder name are longer so they cannot be guessed.
* Security: a damaged or hostile backup can no longer make MoveKit read a huge record into memory.
* Security: downloads send `X-Content-Type-Options: nosniff`.
* The comparison of files for push and pull uses less memory on very large sites.

= 0.9.0 =
* Push and pull send only the files that changed. Files that are already the same on both sites are left out, which makes repeat transfers of large sites much faster. Untick "Send only files that changed", or use `--all-files` in WP-CLI, to send everything.
* Files put in place by copying, on hosts that refuse renames, keep their original modification date.

= 0.8.0 =
* Undo restore: for a day after a restore, push or pull, put the site back as it was. WP-CLI: undo.
* If putting restored files in place fails, the replaced files are put back.
* Fixed restores of sites with foreign keys, such as WooCommerce, when the table prefix stays the same.
* Search-replace only replaces whole addresses, and keeps serialized values with trailing text intact.
* Checks free disk space and the PHP and WordPress versions before a restore, push or pull.
* Only one restore, push or pull runs at a time.
* Skip files: leave out files and folders by name or path, on the screen and with --exclude. .git, node_modules and host must-use plugins are always left out.
* Symlinked folders are backed up and listed in the log.
* Clears page builder CSS, OPcache and page caches after a restore.
* A live site that allows search engines keeps allowing them after a restore.
* Restored files get the host's default permissions.
* Running out of memory or time now gives a clear message instead of an endless spinner. Large tables are exported and searched in batches sized by bytes.
* Clearer warnings before replacing a live site, and an explanation when a local site's key is pasted into a live site.
* The copy kept for undo, and leftovers of interrupted transfers, are removed after a day by WP-Cron, even if MoveKit is not opened again.
* Accessibility: keyboard access to Choose file, progress and results announced to screen readers, and buttons that name their backup or site.
* Push and pull also send their signature in the address, for hosts that drop unknown request headers or cache the REST API. Refused connections now say why.

= 0.7.1 =
* Push and pull require https, except for local development sites.
* Uploaded backups get a random name ending, so they cannot be guessed.
* Stricter checks on backup files and on addresses received from other sites.

= 0.7.0 =
* Push and pull keep trying for 5 minutes when the connection drops, with a countdown.
* A transfer that still can't connect pauses instead of failing. Resume it, or Clean up its leftovers on both sites. WP-CLI: resume and discard.
* Restored files are staged and switched in at the end, just before the database.
* Unfinished jobs older than a day are cleaned up automatically, including temporary database tables.

= 0.6.0 =
* Choose what to include in a backup, push or pull: database, media, plugins, themes and other files.
* WP-CLI export, push and pull take --only.
* Backups list marks partial backups.

= 0.5.2 =
* MoveKit replies tell host and CDN caches not to store them, so push and pull work on hosts that cache the REST API.

= 0.5.1 =
* Clear message, with the fix, when a firewall such as Hostinger CDN or Cloudflare blocks push, pull or connecting.
* Firewall refusals are no longer retried.

= 0.5.0 =
* Push and pull: connect two sites with a connection key and copy one over the other directly.
* WP-CLI commands key, connect, sites, push and pull.

= 0.4.0 =
* Renamed to MoveKit – Site Migration & Backup (slug movekit-migration-backup).

= 0.3.0 =
* Admin screen uses the WordPress REST API. All text is translatable.

= 0.2.0 =
* Admin screen: create, download, upload, restore and delete backups, with resume after a page reload.

= 0.1.0 =
* Backup and restore engine with WP-CLI commands.
