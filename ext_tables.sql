# Personal GitLab/GitHub connections of backend users. No TCA on purpose: rows are only
# managed through the module, so tokens never show up in the list module or in forms.
CREATE TABLE tx_unitytracker_connection (
	uid int(11) unsigned NOT NULL auto_increment,
	be_user int(11) unsigned DEFAULT '0' NOT NULL,
	provider varchar(16) DEFAULT '' NOT NULL,
	host varchar(255) DEFAULT '' NOT NULL,
	label varchar(255) DEFAULT '' NOT NULL,
	account varchar(255) DEFAULT '' NOT NULL,
	token text,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY be_user (be_user)
);

# Saved overview filters. Owner edits, members of be_groups (comma list) may use them. No TCA either.
CREATE TABLE tx_unitytracker_filter (
	uid int(11) unsigned NOT NULL auto_increment,
	be_user int(11) unsigned DEFAULT '0' NOT NULL,
	title varchar(255) DEFAULT '' NOT NULL,
	definition text,
	be_groups varchar(255) DEFAULT '' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY be_user (be_user)
);
