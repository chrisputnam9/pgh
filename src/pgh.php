<?php
/**
 * pgh
 */
Class Pgh extends Console_Abstract
{
    const VERSION = "1.0.0";

    // Name of script and directory to store config
    const SHORTNAME = 'pgh';

    /**
     * Callable Methods
     */
    protected static $METHODS = [
        'get',
        'post',
    ];

    protected static $HIDDEN_CONFIG_OPTIONS = [
        'api_key',
        'api_cache_lifetime',
    ];

    // Constants
    public const APP_URL = "https://api.github.com";
    public const API_URL = "https://github.com/";

    // Config Variables
    protected $__api_key = ["Github Personal Access Token", "string"];
    public $api_key = "";

    protected $__api_cache = ["Whether to cache results"];
    public $api_cache = true;

    protected $__api_cache_lifetime = ["How long to cache results in seconds (if enabled)"];
    public $api_cache_lifetime = 604800; // Default: 1 week

	public $update_version_url = "https://raw.githubusercontent.com/chrisputnam9/pgh/master/README.md";

    protected $___get = [
        "GET data from the Github API.  Refer to https://docs.github.com/en/rest/reference",
        ["Endpoint slug, eg. 'projects'", "string"],
        ["Fields to output in results - comma separated, false to output nothing, * to show all", "string"],
    ];
	public function get($endpoint, $output=true, $return_headers=false, $output_progress=false)
    {
        // Clean up endpoint
        $endpoint = trim($endpoint, " \t\n\r\0\x0B/");

        // Check for valid cached result if cache is enabled
        $body = "";
        if ($this->api_cache and !$return_headers)
        {
            $this->log("Cache is enabled - checking...");

            $body = $this->getAPICacheContents($endpoint);
            if (!empty($body))
            {
                $body_decoded = json_decode($body);
                if (empty($body_decoded) and !is_array($body_decoded))
                {
                    $this->warn("Invalid cached data - will try a fresh call", true);
                    $body="";
                }
                else
                {
                    $body = $body_decoded;
                }
            }
        }
        else
        {
            $this->log("Cache is disabled");
        }

        if (empty($body) and !is_array($body))
        {
            $this->log("Absent cache data, running fresh API request");

            // Get API curl object for endpoint
            $ch = $this->getAPICurl($endpoint, $output_progress);

            // Default to limit -1
            $this->updateCurlArgs($ch, [
                '$limit' => -1
            ]);

            // Execute and check results
            list($body, $headers) = $this->runAPICurl($ch, null, [], $output_progress);

            // Cache results
            $body_json = json_encode($body, JSON_PRETTY_PRINT);
            $this->setAPICacheContents($endpoint, $body_json);
        }

        if ($output)
        {
            if (empty($body->data))
            {
                $this->output('No data in response.');
            }
            else
            {
                $this->outputAPIResults($body->data, $output);
            }
        }

        if ($return_headers)
        {
            return [$body, $headers];
        }

        return $body;
    }

    protected $___post = [
        "POST data to the Github API.  Refer to https://docs.github.com/en/rest/reference",
        ["Endpoint slug, eg. 'projects'", "string"],
        ["JSON (or HJSON) body to send", "string"],
        ["Fields to output in results - comma separated, false to output nothing, * to show all", "string"],
        ["Whether to return headers", "boolean"],
        ["Whether to output progress", "boolean"],
    ];
	public function post($endpoint, $body_json=null, $output=true, $return_headers=false, $output_progress=false)
    {
        return $this->_sendData('POST', $endpoint, $body_json, $output, $return_headers, $output_progress);
    }

        /**
         * Send data to API via specified method
         */
        protected function _sendData($method='POST', $endpoint, $body_json=null, $output=true, $return_headers=false, $output_progress=false)
        {
            // Clean up endpoint
            $endpoint = trim($endpoint, " \t\n\r\0\x0B/");

            // Check JSON
            if (is_null($body_json))
            {
                $this->error("JSON body to send is required");
            }

            if (is_string($body_json))
            {
                // Allow Human JSON to be passed in - more forgiving
                $body = $this->json_decode($body_json, ['keepWsc'=>false]);
                if (empty($body))
                {
                    $this->error("Invalid JSON body - likely syntax error. Make sure to use \"s and escape them as needed.");
                }
            }
            else
            {
                $body = $body_json;
            }

            // Wrap in data key if needed
            if (!isset($body->data))
            {
                $data = $body;
                $body = new StdClass();
                $body->data = $data;
            }
            $body_json = json_encode($body);

            // Get API curl object for endpoint
            $ch = $this->getAPICurl($endpoint, $output_progress);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_POSTFIELDS => $body_json,
            ]);

            // Execute and check results
            list($body, $headers) = $this->runAPICurl($ch, null, [], $output_progress);

            if ($output)
            {
                if (empty($body->data))
                {
                    $this->output('No data in response.');
                }
                else
                {
                    $this->outputAPIResults($body->data, $output);
                }
            }

            if ($return_headers)
            {
                return [$body, $headers];
            }

            return $body;
        }

    /**
     * Prep Curl object to hit Github API
     * - endpoint should be api endpoint to hit
     */
    protected function getAPICurl($endpoint, $output_progress=false)
    {
        $this->setupAPI();
        $url = self::API_URL . '/' . $endpoint;
        if ($output_progress)
        {
            $this->output("Running API request to **".$url."**");
        }
        $ch = $this->getCurl($url);

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key,
            ),
        ]);

        return $ch;
    }

    /**
     * Get link for a single API result object
     */
    public function getResultLink($item, $type='')
    {
        $app_url = self::APP_URL;

        $item_id = null;

        if (is_object($item))
        {

            if (empty($type))
            {
                $type = empty($item->resource_type) ? "" : $item->resource_type;
            }

            $item_id = $item->gid; 
        }
        else
        {
            $item_id = $item;
        }

        if ($type=='project')
        {
            return $app_url . "/0/" . $item_id;
        }

        if ($type=='task')
        {
            return $item->permalink_url;
        }

        return "NOT YET IMPLEMENTED";
    }

    /**
     * Output API Results with links
     */
    public function outputAPIResults ($body, $output=true)
    {
        if (is_string($output))
        {
            $output = trim($output);
        }

        if ($output == false or $output === "false") return;

        if (is_string($output))
        {
            $output = explode(",", $output);
            $output = array_map('trim', $output);
        }
        else
        {
            $output = [];
        }

        if (!is_array($body))
        {
            $body = [$body];
        }

        foreach ($body as $result)
        {
            // todo remove if not needed
            //$type = $this->getResultType($result);
            //$name_field = isset($this->name_field[$type]) ? $this->name_field[$type] : "name";
            $name_field = "name";

            $name = "";
            if (isset($result->$name_field))
            {
                $name = $result->$name_field;
            }
            elseif (isset($result->name))
            {
                $name = $result->name;
            }
            elseif (isset($result->content))
            {
                $name = $result->content;
            }
            else
            {
                $this->warn("Unable to find name field on this content type");
                $this->output($result);
            }

            $link = $this->getResultLink($result);

            $max_width = $this->getTerminalWidth() - (strlen($link) + 4);

            $name = $this->parseHtmlForTerminal($name);
            $name = trim($name);
            if (strlen($name) > $max_width)
            {
                $name = substr($name, 0, $max_width-3) . '...';
            }
            $name = str_pad($name, $max_width);

            // todo implement by type
            $id_output = "-"; //str_pad("(" . $result->id . ")", 15);

            // $this->output("$id_output $name [$link]");
            $this->output("$name [$link]");

            foreach ($output as $output_field)
            {
                if ($output_field == '*')
                {
                    foreach ($result as $field => $value)
                    {
                        $value = $this->stringify($value);
                        $this->output(" -- $field: $value");
                    }
                }
                else
                {
                    $value = isset($result->$output_field) ? $result->$output_field : "";
                    $value = $this->stringify($value);
                    $this->output(" -- $output_field: $value");
                }
            }
        }

        $this->hr();
        $this->output("Total Results: " . count($body));
    }

    /**
     * Get results from pre-prepared curl object
     *  - Handle errors
     *  - Parse results
     */
    protected function runAPICurl($ch, $close=true, $recurrance=[], $output_progress=false)
    {
        if (!is_array($recurrance)) $recurrance=[];
        $recurrance = array_merge([
            'recurring' => false,
            'complete' => 0,
            'total' => 1,
        ], $recurrance);

        // Prep to receive headers
        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$headers)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2)
                {
                    return $len;
                }

                $headers[strtolower(trim($header[0]))][] = trim($header[1]);

                return $len;
            }
        );

        if ($output_progress and !$recurrance['recurring']) $this->outputProgress($recurrance['complete'], $recurrance['total'], "initial request");

        // Execute
        $body = $this->execCurl($ch);

        // Get response code
        $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // Make sure valid response
        if ( empty($body) ) {
            $this->error("Request Error: " . curl_error($ch), false);
            $this->warn("Request may have failed", true);
        }

        if (
            $response_code < 200
            or $response_code > 299
        ) {
            $this->error("Response: $response_code", false);
            $this->error($body, false);
            $this->warn("Request may have failed", true);
        }

        // Process response
        $body_decoded = json_decode($body);
        if (empty($body_decoded) and !is_array($body_decoded))
        {
            $this->error("Invalid response", false);
            $this->error($response_code, false);
            $this->error($body, false);
            $this->warn("Request may have failed", true);
        }
        $body = $body_decoded;

        if ($close)
        {
            curl_close($ch);
        }

        return [$body, $headers];
    }

    /**
     * Set up Github API data
     * - prompt for any missing data and save to config
     */
    protected function setupAPI()
    {
        $api_key = $this->api_key;
        if (empty($api_key))
        {
            $api_key = $this->input("Enter Github Personal Access Token (from https://github.com/settings/tokens)", null, true);
            $api_key = trim($api_key);
            $this->configure('api_key', $api_key, true);
        }

        $this->saveConfig();
    }

    /**
     * Get API cache contents
     */
    protected function getAPICacheContents($endpoint)
    {
        return $this->getCacheContents(
            $this->getAPICachePath($endpoint),
            $this->api_cache_lifetime
        );
    } 

    /**
     * Set API cache contents
     */
    protected function setAPICacheContents($endpoint, $contents)
    {
        return $this->setCacheContents(
            $this->getAPICachePath($endpoint),
            $contents
        );
    } 

    /**
     * Get cache path for a given endpont
     */
    protected function getAPICachePath($endpoint)
    {
        $cache_path = ['github-api'];

        $url_slug = preg_replace("/[^0-9a-z_]+/", "-", self::API_URL);
        $cache_path[]= $url_slug;

        $endpoint_array = explode("/", $endpoint . ".json");
        $cache_path = array_merge($cache_path, $endpoint_array);

        return $cache_path;
    }

}

if (empty($__no_direct_run__))
{
    // Kick it all off
    Pgh::run($argv);
}

// Note: leave this for packaging ?>
