<?php

////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2006  Phorum Development Team                              //
//   http://www.phorum.org                                                    //
//                                                                            //
//   This program is free software. You can redistribute it and/or modify     //
//   it under the terms of either the current Phorum License (viewable at     //
//   phorum.org) or the Phorum License that was distributed with this file    //
//                                                                            //
//   This program is distributed in the hope that it will be useful,          //
//   but WITHOUT ANY WARRANTY, without even the implied warranty of           //
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.                     //
//                                                                            //
//   You should have received a copy of the Phorum License                    //
//   along with this program.                                                 //
////////////////////////////////////////////////////////////////////////////////

if(!defined("PHORUM")) return;

/**
 * Mainly used for cirular loop protection in template includes. The
 * default value should be more than sufficient for any template.
 */
define("PHORUM_TEMPLATES_MAX_INCLUDE_DEPTH", 50);

/**
 * Converts a Phorum template into PHP code and writes the resulting code
 * to disk. This is the only call from templates.php that is called from
 * outside this file. All other functions are used internally for the
 * template compiling process.
 *
 * @param $page - The template page name (as used for phorum_get_template())
 * @param $infile - The template input file to process
 * @param $outfile - The PHP file to write the resulting code to.
 */
function phorum_import_template($page, $infile, $outfile)
{
    // Template pass 1:
    // Recursively process all template {include ...} statements, to
    // construct a single template data block.
    list ($template, $dependancies) = phorum_import_template_pass1($infile);

    // Template pass 2:
    // Translate all other template statements into PHP code.
    $template = phorum_import_template_pass2($template);

    // Write the compiled template to disk.
    //
    // For storing the compiled template, we use two files. The first one
    // has some code for checking if one of the dependant files has been
    // updated and for rebuilding the template if this is the case.
    // This one loads the second file, which is the compiled template itself.
    //
    // This two-stage loading is needed to make sure that syntax
    // errors in a template file won't break the depancy checking process.
    // If both were in the same file, the complete file would not be run
    // at all and the user would have to clean out the template cache to
    // reload the template once it was fixed. This way user intervention
    // is never needed.

    $stage1file = $outfile;
    $stage2file = $outfile . "-stage2";

    // Output file for stage 1. This file contains code to check the file
    // dependancies. If one of the files that the template depends on is
    // changed, the template has to be rebuilt.
    $checks = array();
    foreach ($dependancies as $file => $mtime) {
        $qfile = addslashes($file);
        $checks[] = "@filemtime(\"$qfile\") > $mtime";
    }
    $qstage1file = addslashes($stage1file);
    $qstage2file = addslashes($stage2file);
    $qpage = addslashes($page);
    $stage1 = "<?php
      if (" . implode(" || ", $checks) . ") {
          @unlink (\"$qstage1file\");
          include(phorum_get_template(\"$qpage\"));
          return;
      } else {
          include(\"$qstage2file\");
      }
      ?>";
    phorum_write_file($stage1file, $stage1);

    // Output file for stage 2. This file contains the compiled template.
    phorum_write_file($stage2file, $template);
}

/**
 * Runs the first stage of the Phorum template processing. In this stage,
 * all {include <template>} statements are recursively resolved. After
 * resolving all includes, a complete single template is constructed.
 * During this process, the function will keep track of all file
 * dependancies for the constructed template.
 *
 * @param $infile - The template file to process.
 * @param $include_depth - Current include depth (only for recursive call).
 * @param $deps - File dependancies (only for recursive call)
 * @return $template - The constructed template data.
 * @return $dependancies - An array containing file dependancies for the
 *     created template data. The keys are filenames and the values are
 *     file modification times.
 */
function phorum_import_template_pass1($infile, $include_depth=0, $deps=array())
{
    $include_depth++;

    if ($include_depth > PHORUM_TEMPLATES_MAX_INCLUDE_DEPTH) die(
        "phorum_import_template_pass1: the include depth has passed " .
        "the maximum allowed include depth of " .
        PHORUM_TEMPLATES_MAX_INCLUDE_DEPTH . ". Maybe some circular " .
        "include loop was introduced? If not, then you can raise the " .
        "value for the PHORUM_TEMPLATES_MAX_INCLUDE_DEPTH definition " .
        "in " . htmlspecialchars(__FILE__) . ".");

    $deps[$infile] = filemtime($infile);

    $template = phorum_read_file($infile);

    // Process {include ...} statements in the template.
    $include_done = array();
    preg_match_all("/\{include\s+(.+?)\}/is", $template, $matches);
    for ($i=0; $i<count($matches[0]); $i++)
    {
        if (isset($include_done[$matches[0][$i]])) continue;

        list ($subout, $subin) = phorum_get_template_file($matches[1][$i]);
        if ($subout == NULL) {
            $replace = phorum_read_file($subin);
        } else {
            list ($replace, $deps) =
                phorum_import_template_pass1($subin, $include_depth, $deps);
        }

        $template = str_replace($matches[0][$i], $replace, $template);

        $include_done[$matches[0][$i]] = 1;
    }

    return array($template, $deps);
}

/**
 * Runs the second stage of Phorum template processing. In this stage,
 * all template statements are translated into PHP code.
 *
 * @param $template - The template data to process.
 * @return $template - The processed template data.
 */
function phorum_import_template_pass2($template)
{
    // This array is used for keeping track of loop variables.
    $loopvars = array();

    // Find and process all template statements in the code.
    preg_match_all("/\{[\!\/A-Za-z].+?\}/s", $template, $matches);
    foreach ($matches[0] as $match)
    {
        // Strip surrounding { .. } from the statement.
        $string=substr($match, 1, -1);
        $string = trim($string);

        // pre-parse pointer variables
        if (strstr($string, "->")){
            $string = str_replace("->", "']['", $string);
    }

        // Process template command statements.
        $parts = explode(" ", $string);
        switch (strtolower($parts[0]))
        {
            // COMMENTS ------------------------------------------------------

            // Syntax:
            //    {! <comment string>}
            // Function:
            //    Adding comments to templates
            //
            // These are only used for commenting template code and they are
            // fully removed from the template.
            //
            case "!":
                $repl = "";
                break;

            // INCLUDE_ONCE --------------------------------------------------

            // Syntax:
            //    {include_once <variable>}
            // Function:
            //    Include a template, at most one time per page.
            //
            // This function was introduced for this change:
            // http://www.phorum.org/cgi-bin/trac.cgi/ticket/68
            // It's now deprecated. In case people really want to use it, we
            // can re-introduce it.

            // INCLUDE_VAR ---------------------------------------------------

            // Syntax: {include_var <variable>}
            // Function: Include a template. The name of the template to
            // include is in the <variable>.
            //
            case "include_var":
                $repl = "<?php include_once phorum_get_template( \$PHORUM[\"DATA\"]['$parts[1]']); ?>";
                break;

            // DEFINE --------------------------------------------------------

            // Syntax:
            //    {define <variable> <value>}
            // Function:
            //    Set definitions that are used by the Phorum core.
            //
            // This will set $PHORUM["TMP"]["<variable>"] = "<value>"
            // This data is not accessible through templating statements (and
            // it's not supposed to be). The data should only be accessed
            // from Phorum core and module code.
            //
            case "define":
                $repl="<?php \$PHORUM[\"TMP\"]['$parts[1]']='";
                array_shift($parts);
                array_shift($parts);
                foreach ($parts as $part) {
                    $repl .= str_replace("'", "\\'", $part) . " ";
                }
                $repl = trim($repl)."'; ?>";
                break;

            // VAR -----------------------------------------------------------

            // Syntax:
            //     {var <variable> <value>}
            // Function:
            //     Set a variable that can be used in the templates.
            //
            // This will set $PHORUM["DATA"]["<variable>"] = "<value>";
            // After this, the variable is usable in template statements like
            // {<variable>} and {IF <variable>}...{/IF}.
            //
            case "var":
                $repl="<?php \$PHORUM[\"DATA\"]['$parts[1]']='";
                array_shift($parts);
                array_shift($parts);
                foreach ($parts as $part) {
                    $repl .= str_replace("'", "\\'", $part) . " ";
                }
                $repl = trim($repl)."'; ?>";
                break;

            // ASSIGN --------------------------------------------------------

            // Syntax:
            //     {assign <variable1> <variable2>}
            // Function:
            //     Assign one variable's value to another variable.
            //
            // TODO: why have a numeric assignment if we have {var ..} ?
            // TODO: and why not implement this totally by {var ..} ?
            // TODO: that can also remove some inconsistency in how
            // TODO: template values are written down (numerical,
            // TODO: PHP constant, string, other template variable).
            // TODO: That way it would be consistent with {if ..}.
            // TODO: To show what I mean, here are the if constructions:
            // TODO: (mydefine was set using define("mydefine", "myvalue"))
            // TODO: {if var1 var2}
            // TODO: {if var1 "string"}
            // TODO: {if var1 123}
            // TODO: {if var1 mydefine}
            // TODO: But for setting values for variables, it's:
            // TODO: {assign var1 var2}
            // TODO: {var var1 string}
            // TODO: {assign var1 123}
            // TODO: {assign var1 mydefine}
            // TODO: It's not consistent.
            // TODO: If it were, then you'd be able to do
            // TODO: {var var1 var2}
            // TODO: {var var1 "string"}
            // TODO: {var var1 123}
            // TODO: {var var1 mydefine}
            //
            case "assign":
                if (defined($parts[2]) || is_numeric($parts[2])){
                    $repl = "<?php \$PHORUM[\"DATA\"]['$parts[1]']=$parts[2]; ?>";
                } else {
                    $index = phorum_determine_index($loopvars, $parts[2]);
                    $repl = "<?php \$PHORUM[\"DATA\"]['$parts[1]']=\$PHORUM['$index']['$parts[2]']; ?>";
                }
                break;

            // LOOP ----------------------------------------------------------

            // Syntax:
            //     {loop <array variable>}
            //         .. loop code ..
            //     {/loop <array variable>}
            // Function:
            //     Loop through all elements of an array variable.
            // Example:
            //     {loop arrayvar}
            //         Element is: {arrayvar}
            //     {/loop arrayvar}
            //
            // The array variable to loop through has to be set in variable
            // $PHORUM["DATA"]["<array variable>"]. While looping through this
            // array, elements are put in $PHORUM["TMP"]["<array variable>"].
            // If constructions like {<array variable>} are used inside the
            // loop, the element in $PHORUM["TMP"] will be used.
            //
            case "loop":
                $loopvars[$parts[1]] = true;
                $index = phorum_determine_index($loopvars, $parts[1]);
                $repl = "<?php \$phorum_loopstack[] = isset(\$PHORUM['TMP']['$parts[1]']) ? \$PHORUM['TMP']['$parts[1]']:NULL; if(isset(\$PHORUM['$index']['$parts[1]']) && is_array(\$PHORUM['$index']['$parts[1]'])) foreach(\$PHORUM['$index']['$parts[1]'] as \$PHORUM['TMP']['$parts[1]']){ ?>";
                break;
            case "/loop":
                if (!isset($parts[1])) print "<h3>Template warning: Missing argument for /loop statement in file '" . htmlspecialchars($tplfile) . "'</h3>";
                $repl="<?php } if(isset(\$PHORUM['TMP']) && isset(\$PHORUM['TMP']['$parts[1]'])) unset(\$PHORUM['TMP']['$parts[1]']); \$phorum_loopstackitem=array_pop(\$phorum_loopstack); if (isset(\$phorum_loopstackitem)) \$PHORUM['TMP']['$parts[1]'] = \$phorum_loopstackitem;?>";
                unset($loopvars[$parts[1]]);
                break;

            // IF/ELSEIF/ELSE ------------------------------------------------

            // Syntax:
            //     {if [not] <condition>}
            //         .. conditional code ..
            //     [{elseif [not] <condition>}
            //         .. conditional code ..]
            //     [{else}
            //         .. conditional code ..]
            //     {/if}
            //
            //     The <condition> can be:
            //     <variable>
            //         True if the variable (template variable or loop
            //         variable) is set and not empty.
            //     <variable> <number|"string"|php defined constant>
            //         Compares the variable to a number, string or constant.
            //     <variable> <othervariable>
            //         Compares the variale to another variable.
            // Function:
            //     Run conditional code.
            // Example:
            //     {if somevariable}somevariable is true{/if}
            //     {if not somevariable 1}somevariable is not 1{/if}
            //     {if thevar "somevalue"}thevar contains "somevalue"{/if}
            //     {if thevar phpdefine}thevar and phpdefine are equal{/if}
            //     {if thevar othervar}thevar and othervar are equal{/if}
            //
            // TODO: An "OR" implementation would be very useful.
            //
            case "elseif":
            case "if":
                // Determine if we're handling "if" or "elseif".
                $prefix = (strtolower($parts[0])=="if") ? "if" : "} elseif";

                // Determine if we need "==" or "!=" for the condition.
                if (strtolower($parts[1]) == "not") {
                    $operator = "!=";
                    array_splice($parts, 1, 1);
                } else {
                    $operator="==";
                }

                // Determine what variable we are comparing to in the condition.
                $index = phorum_determine_index($loopvars, $parts[1]);

                // If there is no part 2, check that the value is set and not empty.
                if (!isset($parts[2])) {
                    if ($operator == "=="){
                        $repl = "<?php $prefix(isset(\$PHORUM['$index']['$parts[1]']) && !empty(\$PHORUM['$index']['$parts[1]'])){ ?>";
                    } else {
                        $repl = "<?php $prefix(!isset(\$PHORUM['$index']['$parts[1]']) || empty(\$PHORUM['$index']['$parts[1]'])){ ?>";
                    }
                }
                // If it is numeric, a PHP defined constant or a string, simply set it as is.
                elseif (is_numeric($parts[2]) || defined($parts[2]) || preg_match('!"[^"]*"!', $parts[2])) {
                    $repl = "<?php $prefix(isset(\$PHORUM['$index']['$parts[1]']) && \$PHORUM['$index']['$parts[1]']$operator$parts[2]){ ?>";
                }
                // We must be comparing to a template variable.
                else {
                    $index_part2 = phorum_determine_index($loopvars, $parts[2]);
                    // This is a really complicated IF we are building.
                    $repl = "<?php $prefix(isset(\$PHORUM['$index']['$parts[1]']) && isset(\$PHORUM['$index_part2']['$parts[2]']) && \$PHORUM['$index']['$parts[1]']$operator\$PHORUM['$index_part2']['$parts[2]']) { ?>";
                }
                break;

            case "else":
                $repl="<?php } else { ?>";
                break;

            case "/if":
                $repl="<?php } ?>";
                break;

            // HOOK ----------------------------------------------------------

            // Syntax:
            //     {hook <hook name> [<param 1> <param 2> .. <param n>]}
            // Function:
            //     Run a Phorum hook. The first parameter is the name of the
            //     hook. Other parameters will be passed on as arguments for
            //     the hook function. One argument will be passed directly to
            //     the hook. Multiple arguments will be passed in an array.
            //
            case "hook":
                // Setup hook arguments.
                $hookargs = array();
                for ($i = 2; !empty($parts[$i]); $i++) {
                    // For supporting the following construction, where the
                    // loopvar is passed to the hook in full:
                    // {LOOP SOMELIST}
                    //   {HOOK some_hook SOMELIST}
                    // {/LOOP SOMELIST}
                    if (isset($loopvars[$parts[$i]])) {
                        $hookargs[] = "\$PHORUM['TMP']['".addslashes($parts[$i])."']";
                    } else {
                        $index = phorum_determine_index($loopvars, $parts[$i]);
                        $hookargs[] = "\$PHORUM['$index']['".addslashes($parts[$i])."']";
                    }
                }

                // Build the replacement string.
                $repl = "<?php if(isset(\$PHORUM['hooks']['".addslashes($parts[1])."'])) phorum_hook('".addslashes($parts[1])."'";
                if (count($hookargs) == 1) {
                    $repl .= "," . $hookargs[0];
                } elseif (count($hookargs) > 1) {
                    $repl .= ",array(" . implode(",", $hookargs) . ")";
                }
                $repl .= ");?>";
                break;

            // VARIABLE ECHO -------------------------------------------------

            // Syntax:
            //     {<variable>}
            // Function:
            //     Echo the value for the <variable> on screen. The <variable>
            //     can be (in order of importance) a PHP constant definition
            //     value, a template loop variable or a template variable.
            //
            default:
                if (defined($parts[0])) {
                    $repl = "<?php echo $parts[0]; ?>";
                } else {
                    $index = phorum_determine_index($loopvars, $parts[0]);
                    $repl = "<?php if (isset(\$PHORUM['$index']['$parts[0]'])) echo \$PHORUM['$index']['$parts[0]']; ?>";
                }
        }

        $template = str_replace($match, $repl, $template);
    }

    $template = 
        "<?php if(!defined(\"PHORUM\")) return; ?>\n" .
        "<?php \$phorum_loopstack = array() ?>\n" .
        $template;

    return $template;
}

/**
 * Determines wheter a template variable should be used from
 * $PHORUM["DATA"] (the default location) or $PHORUM["TMP"]
 * (for loop variables).
 *
 * @param $loopvars - The current array of loop variables.
 * @param $varname - The name of the variable for which to do the lookup.
 * @return The index for the $PHORUM array; either "DATA" or "TMP".
 */
function phorum_determine_index($loopvars, $varname)
{
    // TODO: this code needs some better documentation.
    if (isset($loopvars) && count($loopvars)) {
        while (strstr($varname, "]")){
            $varname = substr($varname, 0, strrpos($varname, "]")-1);
            if (isset($loopvars[$varname])) {
                return "TMP";
                break;
            }
        }
    }

    return "DATA";
}

/**
 * Reads a file from disk and returns the contents of that file.
 *
 * @param $file - The filename of the file to read.
 * @return $data - The contents of the file.
 */
function phorum_read_file($file)
{
    // Check if the file exists.
    if (! file_exists($file)) die(
        "phorum_get_file_contents: file \"" . htmlspecialchars($file) . "\" " .
        "does not exist");

    // In case we're handling a zero byte large file, we don't read it in.
    // Running fread($fp, 0) gives a PHP warning.
    $size = filesize($file);
    if ($size == 0) return "";

    // Read in the file contents.
    if (! $fp = fopen($file, "r")) die(
        "phorum_get_file_contents: failed to read file " .
        "\"" . htmlspecialchars($file) . "\"");
    $data = fread($fp, $size);
    fclose($fp);

    return $data;
}

/**
 * Writes a file do disk, with thorough error checking.
 *
 * @param $file - The filename of the file to write the data to.
 * @param $data - The data to put in the file.
 */
function phorum_write_file($file, $data)
{
    // Write the data to the file.
    if (! $fp = fopen($file, "w")) die(
        "phorum_write_file: failed to write to file " .
        "\"" . htmlspecialchars($file) . "\". This is probably caused by " .
        "the file permissions on your Phorum cache directory"); 
    fputs($fp, $data);
    if (! fclose($fp)) die(
        "phorum_write_file: error on closing the file " .
        "\"" . htmlspecialchars($file) . "\". Is your disk full?");

    // A special check on the created outputfile. We have seen strange
    // things happen on Windows2000 where the webserver could not read
    // the file it just had written :-/
    if (! $fp = fopen($file, "r")) die(
        "Failed to write a usable compiled template to the file " .
        "\"" . htmlspecialchars($outfile) . "\". The file was created " .
        "successfully, but it could not be read by the webserver " .
        "afterwards. This is probably caused by the filepermissions " .
        "on your cache directory."
    );
    fclose($fp);
}


?>
