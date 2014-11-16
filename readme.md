# phpMyBackup

PHP shell script for backing up MySQL databases.


## Features

- backups are contained to a folder named by the date (yyyy-mm-dd format)
- creates a file with the structure of each table
- creates a file with the data of each table
- files are tarred and gzipped after the backup
- files can be GPG encrypted
- pruning script provided to limit the number of backups retained


## Requirements

PHP 5.x with CLI support


## Installation

1. Create a MySQL user *backupuser* with password *backupuser* that has
   read access to all tables

    $ mysql -u root -p
    > GRANT SELECT ON *.* TO 'backupuser'@'localhost' IDENTIFIED BY 'backupuser';

1. Create directories to hold the backups

    $ mkdir /backup/databases
    $ cd /backup/databases
    $ mkdir daily weekly monthly yearly

1. Clone the phpMyBackup repository into /usr/local/scripts/phpmybackup

    $ mkdir -p /usr/local/scripts
    $ cd /usr/local/scripts
    $ hg clone https://paulrentschler@bitbucket.org/paulrentschler/phpmybackup

1. Create a script to handle creating and pruning the daily backups
   in /usr/local/scripts/dbbackup

    #!/usr/bin/env php

    <?php
    /**
     * Shell script to backup the local MySQL databases daily
     *
     * This script is meant to be run as daily via a cron job but can
     * also be run manually as a shell script.
     */

    // set the default timezone
    date_default_timezone_set('America/New_York');

    // specify the base backup path
    $backupPath = '/backup/databases/daily/';

    // create a directory for today
    $backupPath .= date('Y-m-d').'/';
    if (file_exists($backupPath)) {
        echo "\nERROR: backup path (".$backupPath.") already exists!\n\n";
        exit;
    }
    mkdir($backupPath);

    // run the backup
    $cmd = escapeshellcmd('/usr/local/scripts/phpmybackup/dbbackup.php localhost backupuser backupuser '.$backupPath);
    system($cmd);

    // prune the backups
    require_once('/usr/local/scripts/phpmybackup/shears.class.php');
    $shears = new Shears(dirname(dirname($backupPath)), 'bak');
    $shears->prune();

    ?>

1. Schedule the script to run via cron by creating /etc/cron.d/mysql-backups

    #
    # Schedule tasks to support the MySQL backups
    #   installed at: /usr/local/scripts/dbbackup
    #

    MAILTO=root

    # Example of job definition:
    # .----------------- minute (0 - 59)
    # |   .------------- hour (0 - 23)
    # |   |  .---------- day of month (1 - 31)
    # |   |  |  .------- month (1 - 12) OR jan,feb,mar,apr ...
    # |   |  |  |  .---- day of week (0 - 6) (Sunday=0 or 7) OR sun,mon,tue,wed,thu,fri,sat
    # |   |  |  |  |
    # *   *  *  *  *  username    command to be executed

    ### Run the MySQL backup every morning at 5am
     00   5  *  *  *  bakuser      /usr/local/scripts/dbbackup/dailybackup.php


