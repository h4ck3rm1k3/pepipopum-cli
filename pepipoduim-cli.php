<?php
/**
 * Pepipopum - Automatic PO Translation via Google Translate 
 * Copyright (C)2009  Paul Dixon (lordelph@gmail.com)
 * $Id: index.php 21080 2009-10-25 10:04:24Z paul $ 
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * 
 * REQUIREMENTS:
 *
 * Requires curl to perform the Google Translate API call but could
 * easily be adapted to use something else or make the HTTP call
 * natively.
 */


/**
 * Define delay between Google API calls (can be fractional for sub-second delays)
 * 
 * This reduces load on the server and plays nice with Google. If you want a faster
 * experience, simply host Pepipopum on your own server and lower this value.
 */ 
define('PEPIPOPUM_DELAY', 1);
 
 /**
 * POProcessor provides a simple PO file parser
 * 
 * Can parse a PO file and calls processEntry for each entry in it
 * Can derive from this class to perform any transformation you
 * like
 */
class POProcessor
{
    public $max_entries=0; //for testing you can limit the number of entries processed
    private $start=0; //timestamp when we started
    
    public function __construct()
    {
        
    }

    /**
     * Set callback function which is passed the completion
     * percentage and remaining time of the parsing operation. This callback
     * will be called up to 100 times, depending on the
     * size of the file.
     * 
     * Callback is a function name, or an array of ($object,$methodname)
     * as is common for PHP style callbacks
     */
    public function setProgressCallback($callback)
    {
#        $this->progressCallback=$callback;
    }


    /**
     * Parses input file and calls processEntry for each recgonized entry
     * and output for all other lines
     * 
     * To track progress, see setProgressCallback
     */
    public function process($inFile)
    {
        set_time_limit(86400);
        $this->start=time();
        
        $msgid=array();
        $msgstr=array();
        $count=0;
        
        $size=filesize($inFile);
        $percent=-1;
        
        $state=0; 
#	echo "going to read $inFile\n";
        $in=fopen($inFile, 'r');
        while (!feof($in))
        {
            $line=trim(fgets($in));
            $pos=ftell($in);
	    if ($pos==0)
	    {
#		echo "error $pos\n";
		return ;
	    }
#            $percent_now=round(($pos*100)/$size);
            # if ($percent_now!=$percent)
            # {
            #     $percent=$percent_now;
            #     $remain='';
            #     $elapsed=time()-$this->start;
            #     if ($elapsed>=5)
            #     {
            #         $total = $elapsed/($percent/100);
            #         $remain=$total-$elapsed;
            #     }
                
            #     $this->showProgress($percent,$remain);
            # }
            
            $match=array();

	    if (preg_match('/^msgctxt /', $line,$match))
	    {
		echo "$line\n";
	    }
            
            switch ($state)
            {
                case 0://waiting for msgid
                    if (preg_match('/^msgid "(.*)"$/', $line,$match))
                    {
                        $clean=stripcslashes($match[1]);
                        $msgid=array($clean);
                        $state=1;
                        
                    }
                    break;
                case 1: //reading msgid, waiting for msgstr
                    if (preg_match('/^msgstr "(.*)"$/', $line,$match))
                    {
                        $clean=stripcslashes($match[1]);
                        $msgstr=array($clean);
                        $state=2;
                    }
                    elseif (preg_match('/^"(.*)"$/', $line,$match))
                    {
                        $msgid[]=stripcslashes($match[1]);
                    }
                    break;
                case 2: //reading msgstr, waiting for blank
                    
                    if (preg_match('/^"(.*)"$/', $line,$match))
                    {
                        $msgstr[]=stripcslashes($match[1]);
                    }
                    elseif (empty($line))
                    {
                        //we have a complete entry
                        $this->processEntry($msgid, $msgstr);
                        $count++;
                        if ($this->max_entries && ($count>$this->max_entries))
                        {
                            break 2;
                        }
                        
                        $state=0;
                    }
                    
                    break;
            }
            
            //comment or blank line?
            if (empty($line) || preg_match('/^#/',$line))
            {
                $this->output($line."\n");
            }
            
        }
        fclose($in);
    }

        
    /**
     * Called whenever the parser recognizes a msgid/msgstr pair in the
     * po file. It is passed an array of strings for the msgid and msgstr
     * which correspond to multiple lines in the input file, allowing you
     * to preserve this if desired.
     * 
     * Default implementation simply outputs the msgid and msgstr without
     * any further processing
     */
    protected function processEntry($msgid, $msgstr)
    {
        $this->output("msgid ");
        foreach($msgid as $part)
        {
            $part=addcslashes($part,"\r\n\"");
            $this->output("\"{$part}\"\n");
        }
        $this->output("msgstr ");
        foreach($msgstr as $part)
        {
            $part=addcslashes($part,"\r\n\"");
            $this->output("\"{$part}\"\n");
        }
    }


    
    /**
     * Internal method to call the progress callback if set
     */
    protected function showProgress($percentComplete, $remainingTime)
    {
        if (is_array($this->progressCallback))
        {
#            $obj=$this->progressCallback[0];
#            $method=$this->progressCallback[1];
            
 #           $obj->$method($percentComplete,$remainingTime);
        }
        elseif (is_string($this->progressCallback))
        {
  #          $func=$this->progressCallback;
  #          $func($percentComplete,$remainingTime);
        }
    }
    
    /**
     * Called to emit parsed lines of the file - override this
     * to provide customised output
     */
    protected function output($str)
    {
        global $output;
        $output.=$str;
    }
    

}

/**
 * Derivation of POProcessor which passes untranslated entries through the Google Translate
 * API and writes the transformed PO to another file
 * 
 */
class POTranslator extends POProcessor
{
    /**
     * Google API requires a referrer - constructor will build a suitable default
     */
    public $referrer;
    
    /**
     * How many seconds should we wait between Google API calls to be nice
     * to google and the server running Pepipopum? Can use a floating point
     * value for sub-second delays
     */
    public $delay=PEPIPOPUM_DELAY;
    
    public function __construct()
    {
        parent::__construct();
        
        //Google API needs to be passed a referrer
        $this->referrer="http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
    }
    
        
    /**
     * Translates a PO file storing output in desired location
     */
    public function translate($inFile, $outFile, $srcLanguage, $targetLanguage)
    {
        $ok=true;
        $this->srcLanguage=$srcLanguage;    
        $this->targetLanguage=$targetLanguage;    
        
#	echo "going to read $inFile and write $outFile\n";
        $this->fOut=fopen($outFile, 'w');
        if ($this->fOut)
        {
            $this->process($inFile);
            fclose($this->fOut);
            
        }
        else
        {
            trigger_error("POProcessor::translate unable to open $outfile for writing", E_USER_ERROR);
            $ok=false;
        }
        
        
        return $ok;
    }
    
    
    
    
    /**
     * Overriden output method writes to output file
     */
    protected function output($str)
    {
        if ($this->fOut)
        {
            fwrite($this->fOut, $str);
            flush();
        }
    }
    
    /**
     * Overriden processEntry method performs the Google Translate API call
     */
    protected function processEntry($msgid, $msgstr)
    {
        $input=implode('', $msgid);
        $output=implode('', $msgstr);
        
        if (!empty($input) && empty($output))
        {
            $q=urlencode($input);
            $langpair=urlencode("{$this->srcLanguage}|{$this->targetLanguage}");
            $url="http://ajax.googleapis.com/ajax/services/language/translate?v=1.0&q={$q}&langpair={$langpair}";
            $cmd="curl -e ".escapeshellarg($this->referrer).' '.escapeshellarg($url);
            
            $result=`$cmd`;
            $data=json_decode($result);
            if (is_object($data) && is_object($data->responseData) && isset($data->responseData->translatedText))
            {
                $output=$data->responseData->translatedText;    
                
                //Google translate mangles placeholders, lets restore them
                $output=preg_replace('/%\ss/', '%s', $output);
                $output=preg_replace('/% (\d+) \$ s/', ' %$1\$s', $output);
                $output=preg_replace('/^ %/', '%', $output);
            
                //have seen %1 get flipped to 1%
                if (preg_match('/%\d/', $input) && preg_match('/\d%/', $output))
                {
                    $output=preg_replace('/(\d)%/', '%$1', $output);
            
                }
            
                //we also get entities for some chars
                $output=html_entity_decode($output);
                
                $msgstr=array($output);
            }
            
            //play nice with google
            usleep($this->delay * 1000);
            
        }
        
        //output entry
        parent::processEntry($msgid, $msgstr);
    }

    
    
}


//simple progress callback which emits some JS to update the
//page with a progress count
function showProgress($percent,$remainingTime)
{
    $time='';
    if (!empty($remainingTime))
    {
        if ($remainingTime<120)
        {
#            $time=sprintf("(%d seconds remaining)",$remainingTime);
        }
        elseif ($remainingTime<60*120)
        {
 #           $time=sprintf("(%d minutes remaining)",round($remainingTime/60));
        }
        else
        {
  #          $time=sprintf("(%d hours remaining)",round($remainingTime/3600));
        }
    }
    flush();
}

function processForm()
{
//    set_time_limit(86400);

#    echo "process form\n";

    $translator=new POTranslator();
    
    if ($_POST['output']=='html')
    {
        //we output to a temporary file to allow later download
#        echo '<h1>Processing PO file...</h1>';
#        echo '<div id="info"></div>';
#        $translator->setProgressCallback('showProgress');
        $outfile = tempnam(sys_get_temp_dir(), 'pepipopum'); 
    }
    else
    {
        //output directly
        header("Content-Type:text/plain");
        $outfile="php://output";
    }
#    var_dump($argv);
    $infilename = "php://stdin";
#    echo "processing $infilename\n";
    $translator->translate($infilename, $outfile, 'en', 'sq');
    
    if ($_POST['output']=='html')
    {
        //show download link
        $leaf=basename($outfile);
        $name=$_FILES['pofile']['name'];
        
#        echo "Completed - <a href=\"index.php?download=".urlencode($leaf)."&name=".urlencode($name)."\">download your updated po file</a>";
    }
    else
    {
        //we're done
        exit;
    }
    
}




if (isset($_GET['download']) && isset($_GET['name']))
{
    //check download file is valid
    $file=sys_get_temp_dir().DIRECTORY_SEPARATOR.$_GET['download'];
    $ok=preg_match('/^pepipopum[A-Za-z0-9]+$/', $_GET['download']);
    $ok=$ok && file_exists($file);
    
    //sanitize name
    $name=preg_replace('/[^a-z0-9\._]/i', '', $_GET['name']);
        
    if ($ok)
    {
        header("Content-Type:text/plain");
        header("Content-Length:".filesize($file));
        header("Content-Disposition: attachment; filename=\"{$name}\"");
        
        readfile($file);
    }
    else
    {
        //fail
        header("HTTP/1.0 404 Not Found");
#        echo "The requested pepipopum output file is not available - it may have expired. <a href=\"index.php\">Click here to generate a new one</a>.";
    }
    exit;
}

#echo "main \n";
$_GET['output']="html";

#if (isset($_POST['output']) && ($_POST['output']=='html'))
#{
    processForm();
#}
?>

