#!/usr/bin/php

<?php
  // ----------------------------------------------------
  // this program is meant to be run as a shell script
  //  or automated as a cron job.
  // ----------------------------------------------------


/*
  Each database gets a folder
  For each database output a structure.sql file that has the structure of all tables
  For each table in the database output the data into a seperate file
  For each database create a restore script that recreates the database, structure, and loads the data
  Create a stats.log file in the main folder with the runtime and all the things created.
*/

  // ----------------------------------------------------
  //  define the MySQL Server variables
     $server = "";
     $username="";
     $password="";
  // ----------------------------------------------------



  // Determine if the backup directory to use was passed as an argument
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

    // open the stats file to log too
    $LOG = fopen($path.date("Y-m-d")."_stats.log", "w");
    fwrite($LOG, "MySQL Database Backup Log\r\n\r\n");
    fwrite($LOG, "Backing Up Server: ".$server."\r\n");
    fwrite($LOG, "Backup Started: ".date("m/d/Y h:i")."\r\n");
    fwrite($LOG, "-----------------------------------------------------------------\r\n");
    $start_timestamp = getmicrotime();

    // get a list of the databases
    $sql = "SHOW databases";
    $dbs = mysql_query($sql, $dbh) or die(mysql_error());

    while ($db = mysql_fetch_array($dbs)) {
      if ($db[0] <> 'test') {
        // do the backup
        backup_database($db[0]);
      }
    }
    mysql_free_result($dbs);
    unset($db);

    // copy the restore program
    copy("/usr/local/backup/dbrestore.php", $path."dbrestore.php");

    // set the permissions appropriately
    $cmd = escapeshellcmd('chmod -R 777 '.$path);
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

    fclose($LOG);
  }

  exit;
?>



<?php
  function backup_database($dbname) {
    global $dbh, $path, $LOG;
    global $server, $username, $password;

    // log it
    fwrite($LOG, "Backing Up Database: ".$dbname."\r\n");

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

  }  // end of function backup_database($dbname)



  function display_syntax_error() {
    // there is no valid backup directory, inform the user
    echo "----------------------------------------------------------------------\n";
    echo " Syntax: ./dbbackup <MySQL Server> <username> <password> <backup dir>\n";
    echo "----------------------------------------------------------------------\n";
  }



  function getmicrotime() {

    list($usec, $sec) = explode(" ",microtime());
    return ((float)$usec + (float)$sec);

  }  // end of function getmicrotime()



  function format_runtime($runseconds) {

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

  }  // end of funtion format_runtime($runseconds)
?>