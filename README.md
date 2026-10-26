# MoveKit – Site Migration & Backup

Move or back up a whole WordPress site between LocalWP, Docker, MAMP or XAMPP and a live host. Push, pull, or use one backup file. No size limit and nothing to buy.

- **Backup and restore:** the database and all of `wp-content` in one checksummed `.movekit` file.
- **Push and pull:** connect two sites once with a key, then copy one over the other. The local site always starts the transfer, so no tunnel is needed.
- **Only what changed:** repeat transfers skip files that are already the same on both sites.
- **Choose what to move:** database, media, plugins, themes, other files, plus a skip list.
- **Undo:** the replaced database and files are kept for a day.
- **Load media from the live site:** pull without your uploads folder and see the live images locally.
- **Safe address replacement:** serialized and JSON data are handled without breaking them.
- **Command line:** `wp movekit export`, `import`, `push`, `pull`, `undo` and more.

Requires WordPress 5.6+ and PHP 7.4+. Single-site installs only for now.

## Install

Download the latest zip from [Releases](../../releases) (or run `bin/build-zip.sh`), then in WordPress go to **Plugins → Add New → Upload Plugin**.

## Develop

```
composer global require wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp dealerdirect/phpcodesniffer-composer-installer
phpcs
bin/build-zip.sh
```

Source layout: `includes/` classes (`MoveKit_*`), `assets/` admin script and styles, `languages/` translation template, `.wordpress-org/` directory banners, icons and screenshots.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
