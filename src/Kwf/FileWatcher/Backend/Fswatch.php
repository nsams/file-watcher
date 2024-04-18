<?php
namespace Kwf\FileWatcher\Backend;
use Kwf\FileWatcher\Event\Delete as DeleteEvent;
use Kwf\FileWatcher\Event\Create as CreateEvent;
use Kwf\FileWatcher\Event\Modify as ModifyEvent;
use Kwf\FileWatcher\Event\Move as MoveEvent;
use Kwf\FileWatcher\Helper\Links as LinksHelper;

class Fswatch extends ChildProcessAbstract
{
    private $_previousMoveFromFile = null;

    public function isAvailable()
    {
        // disable fswatch for Apple-silicon macs as it regularly fails
        if (php_uname('m') === 'arm64') return false;

        exec("fswatch --version 2>&1", $out, $ret);
        return $ret == 0;
    }

    protected function _getCmd()
    {
        $excludeRegEx = array();
        foreach ($this->_excludePatterns as $e) {
            if (substr($e, -1) == '*') $e = substr($e, 0, -1); //not needed
            $excludeRegEx[] = str_replace(
                array(
                    '.',
                    '*',
                ),
                array(
                    '\\.',
                    '.*'
                ),
                $e);
        }
        $excludeRegEx = implode('|', $excludeRegEx);
        $cmd = "fswatch --insensitive --recursive --event-flags --latency=0.0001 --event-flag-separator=, --extended ";
        if ($excludeRegEx) $cmd .= "--exclude=".escapeshellarg($excludeRegEx).' ';


        $paths = $this->_paths;
        if ($this->_followLinks) {
            $cmd .= "--follow-links ";
        }

        $cmd .= implode(' ', $paths);
        return $cmd;
    }

    protected function _getEventFromLine($line)
    {
        if (!preg_match('#^(.*) ([^ ]*?)(Created|Removed|Renamed|Updated)(,.+)?,(IsFile|IsDir)$#', trim($line), $m)) {
            $this->_logger->error("unknown event: $line");
            return;
        }
        $file = $m[1];
        $ev = $m[3];

        //fswatch buffers create+delete sometimes to one event
        if ($ev == 'Created' && strpos($m[4], 'Removed') !== false && !file_exists($file)) {
            $ev = 'Removed';
        } else if ($ev == 'Removed' && strpos($m[4], 'Created') !== false && file_exists($file)) {
            $ev = 'Created';
        }

        if ($ev == 'Updated') {
            return new ModifyEvent($file);
        } else if ($ev == 'Created') {
            return new CreateEvent($file);
        } else if ($ev == 'Removed') {
            return new DeleteEvent($file);
        } else if ($ev == 'Renamed') {
            //rename create two lines, first the source filename, second the destination filename
            if (!$this->_previousMoveFromFile) {
                $this->_previousMoveFromFile = $file;
            } else {
                return new MoveEvent($this->_previousMoveFromFile, $file);
            }
        }
    }
}
