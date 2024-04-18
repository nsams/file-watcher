<?php
namespace Kwf\FileWatcher\Backend;
use Kwf\FileWatcher\Event\Delete as DeleteEvent;
use Kwf\FileWatcher\Event\Create as CreateEvent;
use Kwf\FileWatcher\Event\Modify as ModifyEvent;
use Kwf\FileWatcher\Event\Move as MoveEvent;
use Kwf\FileWatcher\Helper\Links as LinksHelper;

class Watchmedo extends ChildProcessAbstract
{
    const SHELL_COMMAND_FORMAT = 'echo "EVENT_TYPE:${watch_event_type} SRC_PATH:\'${watch_src_path}\' DEST_PATH:\'${watch_dest_path}\'"';
    const EVENT_LINE_REGEXP = '#^EVENT_TYPE:([a-z]+)\sSRC_PATH:u?\'([^\']+)\'\sDEST_PATH:(u?\'([^\']+)\')?#';

    public function isAvailable()
    {
        exec("watchmedo --version 2>&1", $out, $ret);
        return $ret == 0;
    }

    protected function _getCmd()
    {
        $exclude = $this->_excludePatterns;
        foreach ($exclude as &$e) {
            $e = '*'.$e;
        }
        // watchmedo does not log anyting by default (maybe because of some settings in Python or PyEnv?)
        // @see: https://github.com/gorakhargosh/watchdog/issues/913
        $cmd = "watchmedo shell-command --command=".escapeshellarg(self::SHELL_COMMAND_FORMAT)." --recursive --ignore-directories ";
        if ($exclude) $cmd .= "--ignore-patterns ".escapeshellarg(implode(';', $exclude)).' ';


        $paths = $this->_paths;
        if ($this->_followLinks) {
            //watchmedo doesn't recurse into symlinks
            //so we add all symlinks to $paths
            $paths = LinksHelper::followLinks($paths, $this->_excludePatterns);
        }

        $cmd .= implode(' ', $paths);
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            //disble output bufferering
            $cmd = "PYTHONUNBUFFERED=1 $cmd";
        } else {
            //on windows disable output buffering using -u
            //the above doesn't work
            $cmd = "python -u -m watchdog.$cmd";
        }
        return $cmd;
    }

    protected function _getEventFromLine($line)
    {
        if (!preg_match(self::EVENT_LINE_REGEXP, trim($line), $m)) {
            $this->_logger->error("unknown event: $line");
            return;
        }
        $regexIgnorePattern = implode('|', str_replace(array('*', '/'), '', $this->_excludePatterns));
        $file = str_replace('\\\\', '/', $m[2]); // windows
        $ev = $m[1];

        if (substr($file, -1) === '~') {
            $this->_logger->debug("ignoring event, since this is a temporary file");
            return;
        }
        // Watchmedo ignore patterns are not working and there seems to be no solution yet, see https://github.com/gorakhargosh/watchdog/issues/798
        // exclude files from events here instead
        if (preg_match("#".$regexIgnorePattern.'#', $file)) {
            $this->_logger->debug("ignoring event, since excludePattern \"$regexIgnorePattern\" was matched in path: $file");
            return;
        }
        if ($ev == 'modified') {
            return new ModifyEvent($file);
        } else if ($ev == 'created') {
            return new CreateEvent($file);
        } else if ($ev == 'deleted') {
            return new DeleteEvent($file);
        } else if ($ev == 'moved') {
            $m[4] = str_replace('\\\\', '/', $m[4]);
            $dest = $m[4];
            return new MoveEvent($file, $dest);
        }
    }
}
