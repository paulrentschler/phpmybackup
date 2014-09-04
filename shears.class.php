<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Prune backup files
 *
 * Prunes daily, date-named backup files into weekly, monthly, and yearly
 * backup directories and restricts all directories to a certain number of
 * backups per directory.
 *
 * PHP version 5
 *
 * @author     Paul Rentschler <paul@rentschler.ws>
 * @copyright  2014 Paul Rentschler
 * @version    1.0
 * @since      2 September 2014
 */
class Shears
{
    protected $extension = '';
    protected $levels = Array();


    public function __construct($basePath, $extension)
    {
        $this->levels = Array(
            'daily'   => Array( 'path' => '', 'maxFiles' => 14, ),
            'weekly'  => Array( 'path' => '', 'maxFiles' =>  6, ),
            'monthly' => Array( 'path' => '', 'maxFiles' =>  6, ),
            'yearly'  => Array( 'path' => '', 'maxFiles' =>  6, ),
            );

        $this->extension = trim($extension);
        if ($this->extension <> '' && substr($this->extension, 0, 1) <> '.') {
            $this->extension = '.'.$this->extension;
        }

        $basePath = trim($basePath);
        if (substr($basePath, -1) <> '/') {
            $basePath .= '/';
        }

        foreach (array_keys($this->levels) as $path) {
            $this->levels[$path]['path'] = $basePath.$path.'/';
        }
    }



    protected function delete($date, $path)
    {
        $source = $this->levels[$path]['path'].date('Y-m-d', $date).$this->extension;
        if (file_exists($source)) {
            return unlink($source);
        }
        return false;
    }



    protected function isEndOfMonth($date)
    {
        $month = date('n', $date);
        $year = date('Y', $date);
        if ($month == 12) {
            $year += 1;
            $month = 1;
        } else {
            $month += 1;
        }
        $nextMonth = strtotime($month.'/1/'.$year);
        $endOfMonth = strtotime('-1 day', $nextMonth);
        if ($date == $endOfMonth) {
            return true;
        }
        return false;
    }



    protected function isEndOfWeek($date)
    {
        // Saturday (6) is considered the end of the week
        return (date('w', $date) == 6);
    }



    protected function isEndOfYear($date)
    {
        $year = date('Y', $date);
        $endOfYear = strtotime('12/31/'.$year);
        return ($date == $endOfYear);
    }



    protected function move($date, $fromLevel, $toLevel)
    {
        $source = $this->levels[$fromLevel]['path'].date('Y-m-d', $date).$this->extension;
        $destination = $this->levels[$toLevel]['path'].date('Y-m-d', $date).$this->extension;
        if (file_exists($source)) {
            return rename($source, $destination);
        }
        return false;
    }



    public function prune()
    {
        foreach (array_keys($this->levels) as $level) {
            $this->prunePath($level);
        }
    }



    protected function prunePath($level)
    {
        $levelIndex = array_search($level, array_keys($this->levels));
        // get the files and sort them descending
        $files = scandir($this->levels[$level]['path'], 1);
        if ($files !== false) {
            $validBackups = Array();
            foreach ($files as $file) {
                if ($file <> '.' && $file <> '..') {
                    if ($this->extension <> '') {
                        if (strrpos($file, $this->extension) !== false) {
                            $date = strtotime(str_replace($this->extension, '', $file));
                        } else {
                            $date = false;
                        }
                    } else {
                        $date = strtotime($file);
                    }
                    if ($date !== false) {
                        $validBackups[] = $date;
                    }
                }
            }

            if (count($validBackups) > $this->levels[$level]['maxFiles']) {
                // remove, from the array, all the files that should be kept at this level
                for ($i = $this->levels[$level]['maxFiles']; $i > 0; $i--) {
                    array_shift($validBackups);
                }

                // prune the remaining files that are no longer needed
                $weeklyIndex = array_search('weekly', array_keys($this->levels));
                $monthlyIndex = array_search('monthly', array_keys($this->levels));
                $yearlyIndex = array_search('yearly', array_keys($this->levels));
                foreach ($validBackups as $date) {
                    if ($levelIndex < $weeklyIndex && $this->isEndOfWeek($date)) {
                        echo 'moving '.date('Y-m-d', $date).' to weekly'."\n";
                        $this->move($date, $level, 'weekly');

                    } elseif ($levelIndex < $monthlyIndex && $this->isEndOfMonth($date)) {
                        echo 'moving '.date('Y-m-d', $date).' to monthly'."\n";
                        $this->move($date, $level, 'monthly');

                    } elseif ($levelIndex < $yearlyIndex && $this->isEndOfYear($date)) {
                        echo 'moving '.date('Y-m-d', $date).' to yearly'."\n";
                        $this->move($date, $level, 'yearly');

                    } else {
                        // doesn't go to another level, delete it
                        echo 'deleting '.date('Y-m-d', $date)."\n";
                        $this->delete($date, $level);
                    }
                }
            }
        }
    }



    public function __testIsEndOf()
    {
        echo "\nTesting isEndOfWeek():\n";
        $dates = Array(
            '2014-01-05' => 'False',
            '2014-01-06' => 'False',
            '2014-01-07' => 'False',
            '2014-01-08' => 'False',
            '2014-01-09' => 'False',
            '2014-01-10' => 'False',
            '2014-01-11' => 'True',
            );
        foreach ($dates as $testDate => $expectedResult) {
            echo $testDate.' ('.$expectedResult.') ';
            echo ($this->isEndOfWeek(strtotime($testDate)) ? 'True' : 'False')."\n";
        }


        echo "\nTesting isEndOfMonth():\n";
        $dates = Array(
            '2000-02-28' => 'False',
            '2000-02-29' => 'True',
            '2000-02-30' => 'False',
            '2014-01-01' => 'False',
            '2014-01-31' => 'True',
            '2014-02-28' => 'True',
            '2014-02-29' => 'False',
            '2014-02-30' => 'False',
            '2014-06-15' => 'False',
            '2014-06-30' => 'True',
            '2014-06-31' => 'False',
            '2014-12-01' => 'False',
            '2014-12-30' => 'False',
            '2014-12-31' => 'True',
            );
        foreach ($dates as $testDate => $expectedResult) {
            echo $testDate.' ('.$expectedResult.') ';
            echo ($this->isEndOfMonth(strtotime($testDate)) ? 'True' : 'False')."\n";
        }


        echo "\nTesting isEndOfYear():\n";
        $dates = Array(
            '2014-01-01' => 'False',
            '2014-01-31' => 'False',
            '2014-06-15' => 'False',
            '2014-08-31' => 'False',
            '2014-12-01' => 'False',
            '2014-12-30' => 'False',
            '2014-12-31' => 'True',
            );
        foreach ($dates as $testDate => $expectedResult) {
            echo $testDate.' ('.$expectedResult.') ';
            echo ($this->isEndOfYear(strtotime($testDate)) ? 'True' : 'False')."\n";
        }
    }
}

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */

?>
