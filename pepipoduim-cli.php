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
        $this->progressCallback=$callback;
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
        $in=fopen($inFile, 'r');
        while (!feof($in))
        {
            $line=trim(fgets($in));
            $pos=ftell($in);
            $percent_now=round(($pos*100)/$size);
            if ($percent_now!=$percent)
            {
                $percent=$percent_now;
                $remain='';
                $elapsed=time()-$this->start;
                if ($elapsed>=5)
                {
                    $total = $elapsed/($percent/100);
                    $remain=$total-$elapsed;
                }
                
                $this->showProgress($percent,$remain);
            }
            
            $match=array();
            
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
            $obj=$this->progressCallback[0];
            $method=$this->progressCallback[1];
            
            $obj->$method($percentComplete,$remainingTime);
        }
        elseif (is_string($this->progressCallback))
        {
            $func=$this->progressCallback;
            $func($percentComplete,$remainingTime);
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
            usleep($this->delay * 1000000);
            
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
            $time=sprintf("(%d seconds remaining)",$remainingTime);
        }
        elseif ($remainingTime<60*120)
        {
            $time=sprintf("(%d minutes remaining)",round($remainingTime/60));
        }
        else
        {
            $time=sprintf("(%d hours remaining)",round($remainingTime/3600));
        }
    }
    echo '<script language="Javascript">';
    echo "document.getElementById('info').innerHTML='$percent% complete $time';";
    echo "</script>\n";
    flush();
}

function processForm()
{
    set_time_limit(86400);
    
    $translator=new POTranslator();
    
    if ($_POST['output']=='html')
    {
        //we output to a temporary file to allow later download
        echo '<h1>Processing PO file...</h1>';
        echo '<div id="info"></div>';
        $translator->setProgressCallback('showProgress');
        $outfile = tempnam(sys_get_temp_dir(), 'pepipopum'); 
    }
    else
    {
        //output directly
        header("Content-Type:text/plain");
        $outfile="php://output";
    }
    
    
    $translator->translate($_FILES['pofile']['tmp_name'], $outfile, 'en', $_POST['language']);
    
    if ($_POST['output']=='html')
    {
        //show download link
        $leaf=basename($outfile);
        $name=$_FILES['pofile']['name'];
        
        echo "Completed - <a href=\"index.php?download=".urlencode($leaf)."&name=".urlencode($name)."\">download your updated po file</a>";
    }
    else
    {
        //we're done
        exit;
    }
    
}

if (isset($_GET['viewsource']))
{
    highlight_file($_SERVER['SCRIPT_FILENAME']);
    exit;
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
        echo "The requested pepipopum output file is not available - it may have expired. <a href=\"index.php\">Click here to generate a new one</a>.";
    }
    exit;
}

if (isset($_POST['output']) && ($_POST['output']=='pofile'))
{
    processForm();
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<meta http-equiv="Content-type" content="text/html;charset=UTF-8" />
<title>Pepipopum - Translate PO file with Google Translate</title>
<style type="text/css">


body
{
    background:#eeeeee;
    margin: 0;
    padding: 0;
    text-align: center;
    
    font-family:Verdana,Arial,Helvetica
}

#main
{
    padding: 3em;
    margin: 1em auto 1em auto;
    width: 50em;
    border:1px solid #dddddd;
    text-align: left;
    background:white;
}

#footer
{
    text-align:right;
    font-size:8pt;
    color:#888888;
    border-top:1px solid #888888;
}

h1
{
    margin-top:0;
}

form
{
    background:#dddddd;
    padding:2em;
    margin:0 2em 0 2em;
    
    -moz-border-radius: 1em;
    -webkit-border-radius: 1em;
    border-radius: 1em;
    
    font-size:0.8em;
}

fieldset
{
    background:#cccccc;
    border:1px solid #aaaaaa;
    margin-bottom:1em;
    padding:1em;
    position:relative;
    
    -moz-border-radius: 0.5em;
    -webkit-border-radius: 0.5em;
    border-radius: 0.5em;
    
}

legend
{
    background:#aaaaaa;
    border:0;
    padding:0 1em 0 1em;
    margin-left:1em;
    color:#ffffff;
    
    position: absolute;
    top: -.5em;
    left: .2em;
    
    
    -moz-border-radius: 0.5em;
    -webkit-border-radius: 0.5em;
    border-radius: 0.5em;
    
}

</style>
</head>

<body>
<div id="main">

<?php
if (isset($_POST['output']) && ($_POST['output']=='html'))
{
    processForm();
}
?>

<h1>Pepipopum - Translate PO files with Google Translate</h1>

<p>PO files originate from the <a href="http://www.gnu.org/software/gettext/gettext.html">GNU gettext</a>
tools and can be generated by a wide variety of other localization tools.</p>

<p>Pepipopum allows you to upload a PO file containing English language strings in the <i>msgid</i>, 
and it uses the <a href="http://code.google.com/apis/ajaxlanguage/">Google Translate API</a> 
to construct a PO file containing translated equivalents in each corresponding <i>msgstr</i></p>

<p>If the PO file already contains a translation for a given msgid, it will not be translated. This 
allows you to upload a proof-read PO and just get translations for any new elements.</p>

<form enctype="multipart/form-data" action="index.php" method="post">
    
     <fieldset>
    <legend>Input</legend>
       <div class="field">
        <div class="label"><label for="pofile">PO File</label></div>
        <div class="input"><input id="pofile" name="pofile" type="file" /></div>
        </div>
    </fieldset>
    
    <fieldset>
    <legend>Output options</legend>

 <div class="field">
        <div class="label"><label for="language">Target Language</label></div>
        <div class="input"><select id="language" name="language">
            <option value="af">Afrikaans</option>
            <option value="sq">Albanian</option>
            <option value="ar">Arabic</option>
            <option value="be">Belarusian</option>
            <option value="bg">Bulgarian</option>
            <option value="ca">Catalan</option>
            <option value="zh-CN">Chinese (Simplified)</option>
            <option value="zh-TW">Chinese (Traditional)</option>
            <option value="hr">Croatian</option>
            <option value="cs">Czech</option>
            <option value="da">Danish</option>
            <option value="nl">Dutch</option>
            <option value="en">English</option>
            <option value="et">Estonian</option>
            <option value="tl">Filipino</option>
            <option value="fi">Finnish</option>
            <option value="fr">French</option>
            <option value="gl">Galician</option>
            <option value="de">German</option>
            <option value="el">Greek</option>
            <option value="iw">Hebrew</option>
            <option value="hi">Hindi</option>
            <option value="hu">Hungarian</option>
            <option value="is">Icelandic</option>
            <option value="id">Indonesian</option>
            <option value="ga">Irish</option>
            <option value="it">Italian</option>
            <option value="ja">Japanese</option>
            <option value="ko">Korean</option>
            <option value="lv">Latvian</option>
            <option value="lt">Lithuanian</option>
            <option value="mk">Macedonian</option>
            <option value="ms">Malay</option>
            <option value="mt">Maltese</option>
            <option value="no">Norwegian</option>
            <option value="fa">Persian</option>
            <option value="pl">Polish</option>
            <option value="pt">Portuguese</option>
            <option value="ro">Romanian</option>
            <option value="ru">Russian</option>
            <option value="sr">Serbian</option>
            <option value="sk">Slovak</option>
            <option value="sl">Slovenian</option>
            <option value="es">Spanish</option>
            <option value="sw">Swahili</option>
            <option value="sv">Swedish</option>
            <option value="th">Thai</option>
            <option value="tr">Turkish</option>
            <option value="uk">Ukrainian</option>
            <option value="vi">Vietnamese</option>
            <option value="cy">Welsh</option>
            <option value="yi">Yiddish</option>
            </select>
        </div>
     </div>

     <div>
     <input id="output_po" name="output" value="pofile" type="radio"/>
     <label for="output_po">Output PO File</label>
     </div>
     
     <div>
     <input id="output_html" name="output" value="html" checked="checked" type="radio"/>
     <label for="output_html">Output progress meter and then provide a download link</label>
     </div>
     </fieldset>
   
    <div>
    <input type="submit" value="Translate File" />
    </div>
    
</form>

<p>You can automate translation by using a tool like <a href="http://curl.haxx.se/">cURL</a> to post a PO file and obtain
a translated result. For example:</p>

<pre>
    curl -F pofile=@<i>input-po-filename</i> \
        -F language=<i>target-language-code</i> \
        -F output=pofile 
        http://pepipopum.dixo.net \
        --output <i>output-po-filename</i> 
         
</pre>


<p>The <a href="?viewsource">PHP5 source code</a> to this software is available under an 
<a href="http://www.fsf.org/licensing/licenses/agpl-3.0.html">Affero GPL licence</a>. Please
note that this installation of Pepipopum introduces a <?php echo PEPIPOPUM_DELAY?> second
delay between each Google API call to reduce load on this server and to play nice with
Google. If you want to go faster, you're encouraged to host your own installation.

</p> 

<p>Why is called "Pepipopum"? I just invented a word which had
'po' in it and was relatively rare on Google! Pronounce it <i>pee-pie-poe-pum</i>.</p>

<p><a href="http://blog.dixo.net/2009/10/24/pepipopum-automatically-translate-po-files-with-google-translate/">Comments and suggestions</a> are welcome.</p>
<div id="footer">
<p><a href="http://blog.dixo.net/about">(c)2009 Paul Dixon</a></p>
</div>
</div>
</body>
</html>
