#!/usr/bin/php

<?php
  /**
   * Shell script for backing up MySQL databases
   *
   * For each database, a folder is created to hold the backup files that are
   * generated. For each database table, the structure is output in a
   * `structure.sql` file and the data is output in a separate `data.sql` file.
   *
   * A `stats.log` file is created in the main backup folder that includes
   * details about everything that was backed up.
   *
   * The entire backup directory is compressed using tar and gzip into a single
   * backup file that can optionally be encrypted with GPG.
   */


  //  define the MySQL Server variables
  $server = "";
  $username="";
  $password="";

  // set the default timezone
  date_default_timezone_set('America/New_York');


  // verify that all the necessary parameters have been provided
  if ($_SERVER['argc'] >= 5) {
    for ($i = 1; $i < 5; $i++) {
      if (trim($_SERVER['argv'][$i]) == '') {
        display_syntax_error();
        exit;
      }
    }

    $server = trim($_SERVER['argv'][1]);
    $username = trim($_SERVER['argv'][2]);
    $password = trim($_SERVER['argv'][3]);
    $path = trim($_SERVER['argv'][4]);
    if ($_SERVER['argc'] == 6) {
      $encryptionKey = trim($_SERVER['argv'][5]);
    }

    if (!file_exists($path)) {
      unset($path);
      echo "The backup directory you provided does not exist.\n";
      display_syntax_error();
      exit;
    }
  } else {
    echo "Incorrect Number of Parameters.\n";
    display_syntax_error();
    exit;
  }


  if (isset($path)) {
    // make sure the last character on the path is a /
    if (substr($path, -1) <> '/') {
      $path .= "/";
    }

    // establish a connection to the database
    if (!(isset($dbh))) {
      $error_msg = "Failed to connect to MySQL database server [".$server."]";
      $dbh = mysql_connect($server, $username, $password) or die($error_msg);
    }

    // start the backup log
    $LOG = fopen($path.date("Y-m-d")."_stats.log", "w");
    fwrite($LOG, "MySQL Database Backup Log\r\n\r\n");
    fwrite($LOG, "Backing Up Server: ".$server."\r\n");
    fwrite($LOG, "Backup Started: ".date("m/d/Y h:i")."\r\n");
    fwrite($LOG, "-----------------------------------------------------------------\r\n");
    $start_timestamp = getmicrotime();

    // output the backup start to the screen
    echo "MySQL database backup for (".$server.") started on: ".date('m/d/Y h:i')."\n\n";

    // get a list of the databases
    $sql = "SHOW databases";
    $dbs = mysql_query($sql, $dbh) or die(mysql_error());
    while ($db = mysql_fetch_array($dbs)) {
      // skip the `test` and `information_schema` databases
      if ($db[0] <> 'test' && $db[0] <> 'information_schema') {
        // do the backup
        backup_database($db[0]);
      }
    }
    mysql_free_result($dbs);
    unset($db);

    // copy the restore program
    //copy("/usr/local/backup/dbrestore.php", $path."dbrestore.php");

    // fix the directory and file group ownership and permissions
    $cmd = escapeshellcmd('chgrp -R adm '.$path);
    system($cmd);
    $cmd = 'chmod -R o-rwx '.substr($path, 0, -1);
    system($cmd);


    if (isset($encryptionKey) && $encryptionKey <> '') {
      // tar and GPG encrypt the backup files
      $dest = substr($path, 0, -1).'.bak';
      $dirs = explode('/', $dest);
      $backupFile = array_pop($dirs);
      chdir($path);
      $cmd = 'tar -cz * | gpg --output ../'.$backupFile.' --encrypt --recipient '.$encryptionKey;
      system($cmd);

    } else {
      // tar up the backup files
      $dest = substr($path, 0, -1).'.tar.gz';
      $dirs = explode('/', $dest);
      $backupFile = array_pop($dirs);
      chdir($path);
      $cmd = 'tar -cz * > ../'.$backupFile;
      system($cmd);
    }

    // fix the permissions on the tar file
    $cmd = escapeshellcmd('chgrp -R adm '.$dest);
    system($cmd);

    // delete the original backup directory
    $cmd = 'rm -rf '.substr($path, 0, -1);
    system($cmd);


    // figure out how long it took
    $end_timestamp = getmicrotime();
    $runseconds = $end_timestamp - $start_timestamp;

    // end the log file
    fwrite($LOG, "-----------------------------------------------------------------\r\n");
    fwrite($LOG, "Backup Finished: ".date("m/d/Y h:i")."\r\n");
    fwrite($LOG, "Backup Process took: ".format_runtime($runseconds)."\r\n");
    fwrite($LOG, "-----------------------------------------------------------------\r\n");
    fwrite($LOG, "\r\n\r\n");

    // output the completion stats to the screen
    echo "\nBackup finished on ".date('m/d/Y h:i')." in ".format_runtime($runseconds)."\n\n";

    fclose($LOG);
  }

  exit;
?>



<?php

  /**
   * Backup all the tables in the specified database
   *
   * @param   string dbname  a string containing the name of the database
   * @return  void
   */
  function backup_database($dbname)
  {
    global $dbh, $path, $LOG;
    global $server, $username, $password;

    // log it
    fwrite($LOG, "Backing Up Database: ".$dbname."\r\n");

    // output progress
    echo "Backing up database: ".$dbname."\n";

    // select the database
    mysql_select_db($dbname, $dbh);

    // make sure a directory exists
    $filename = $path.$dbname;
    if (!file_exists($filename)) {
      mkdir($filename, 0777);
    }
    fwrite($LOG, "Files Stored at: ".$filename."\r\n");

    // now get a list of all the tables
    $sql = "SHOW tables FROM ".$dbname;
    $tables = mysql_query($sql, $dbh) or die(mysql_error());

    while ($table = mysql_fetch_array($tables)) {
      $tablename = $table[0];
      if ($dbname == 'mysql' && $tablename == 'event') {
        // mysqldump throws a warning and skips the mysql.event table so this
        //   should hopefully avoid the warning
        continue;
      }

      // output the structure
      $filename = date("Y-m-d")."_structure_".$tablename.".sql";
      $cmd = escapeshellcmd('mysqldump --skip-opt --host='.$server.' --user='.$username.' --password='.$password.' -d '.$dbname.' '.$tablename);
      $cmd .= ' > '.escapeshellcmd($path.$dbname.'/'.$filename);
      system($cmd);
      fwrite($LOG, "Table Structure for Table [".$tablename."] created: ".$filename."\r\n");

      // output the table data
      $filename = date("Y-m-d")."_data_".$tablename.".sql";
      $cmd = escapeshellcmd('mysqldump --skip-opt --host='.$server.' --user='.$username.' --password='.$password.' -t '.$dbname.' '.$tablename);
      $cmd .= ' > '.escapeshellcmd($path.$dbname.'/'.$filename);
      system($cmd);
      fwrite($LOG, "Table Data for Table [".$tablename."] created: ".$filename."\r\n");
    }
    mysql_free_result($tables);
    unset($table);

    fwrite($LOG, "\r\n");
  }



  /**
   * Output the syntax for the command
   *
   * @return  void
   */
  function display_syntax_error()
  {
    echo "----------------------------------------------------------------------\n";
    echo " Syntax: ./dbbackup <MySQL Server> <username> <password> <backup dir>\n";
    echo "----------------------------------------------------------------------\n";
  }



  /**
   * Get the current time in microseconds
   *
   * @return  float  the current time in microseconds
   */
  function getmicrotime()
  {
    list($usec, $sec) = explode(" ",microtime());
    return ((float)$usec + (float)$sec);
  }



  /**
   * Format the microsecond run time into a human-friendly time
   *
   * Example output:  2 Hours 14 Minutes 42 Seconds
   *
   * @param   float runseconds  a float of microseconds to display
   * @return  string  a string representing the microseconds value in hours,
   *                  minutes, and seconds
   */
  function format_runtime($runseconds)
  {
    if ($runseconds > 3600) {
      $hours = floor($runseconds / 3600);
      $runseconds -= ($hours * 3600);
    }
    if ($runseconds > 60) {
      $minutes = floor($runseconds / 60);
      $runseconds -= ($minutes * 60);
    }

    $runtime = "";
    if (isset($hours)) {
      $runtime .= $hours." Hours ";
    }
    if (isset($minutes)) {
      $runtime .= $minutes." Minutes ";
    }
    $runtime .= number_format($runseconds, 2)." Seconds";

    return $runtime;
  }

?>

