<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Version details
 *
 * @package    repository_resourcespace
 * @copyright  2018 Anders Jørgensen
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("$CFG->dirroot/repository/resourcespace/io_print.php"); 

class repository_resourcespace extends repository {

    public function __construct($repositoryid, $context, array $options, $readonly) {
        parent::__construct($repositoryid, $context, $options, $readonly);
        $this->config           = get_config('resourcespace');
        $this->resourcespace_api_url = get_config('resourcespace', 'resourcespace_api_url');
        $this->api_key          = get_config('resourcespace', 'api_key');
        $this->api_user         = get_config('resourcespace', 'api_user');
        $this->enable_help      = get_config('resourcespace', 'enable_help');
        $this->enable_help_url  = get_config('resourcespace', 'enable_help_url');
    }

    public function get_listing($path = '', $page = '') {
        if ($path !== '') {
            // Redirect to search, asking for filesa within the given collection
            $listArray = $this->search(sprintf('!collection%s', $path), $page);

            return $listArray;
        }

        $listArray = array(
            'list' => $this->do_search_collections(),
            'norefresh' => true,
            'nologin' => true,
            'dynload' => true,
            'issearchresult' => false,
        );

        if ($this->enable_help == 1) {
            $listArray['help'] = "$this->enable_help_url";
        }



        return $listArray;
    }


    // public function print_search() {
    //     $search = '<input class="form-control" id="reposearch" name="s" placeholder="Search" type="search">';
    //     return $search;
    // }

    public function search($searchText, $page = 0) {
        $listArray = array(
            'list' => array(),
            'norefresh' => true,
            'nologin' => true,
            'dynload' => true,
            'issearchresult' => true,
        );

        if ($this->enable_help == 1) {
            $listArray['help'] = "$this->enable_help_url";
        }

        $collections = $this->do_search_collections($searchText);
        $resources = $this->do_search_resources($searchText);

        $listArray['list'] = array_merge($collections, $resources);

        return $listArray;
    }

    public function get_file($url, $filename = '') {
        // We have to catch the url, and make an additional request to the resourcespace api,
        // to get the actual filesource.

        // $fileInfo = explode(',', $url);
        $fileInfo = explode(',', unserialize($url)->path);

        $resourceUrl = $this->make_api_request('get_resource_path', array(
            'param1' => $fileInfo[0], // $resource
            'param2' => '0',          // $getfilepath
            'param3' => '',           // $size
            'param5' => $fileInfo[1], // $extension
        ));

        // Call the method of the parent class, then overwrite the URL back to what was passed to us
        $result = parent::get_file($resourceUrl, $filename);
        $result['url'] = $url;

        return $result;
    }

    /**
     * Prepare file reference information.
     *
     * @inheritDocs
     */
    public function get_file_reference($source) {
        rs_print("SOURCE: " .$source);
        global $USER;
        $reference = new stdClass;
        $reference->userid = $USER->id;
        $reference->username = fullname($USER);
        $reference->path = $source;

        // Determine whether we are downloading the file, or should use a file reference.
        $usefilereference = optional_param('usefilereference', false, PARAM_BOOL);
        if ($usefilereference) {
            $fileInfo = explode(',', $source);
            $resourceUrl = $this->make_api_request('get_resource_path', array(
                'param1' => $fileInfo[0], // $resource
                'param2' => '0',          // $getfilepath
                'param3' => '',           // $size
                'param5' => $fileInfo[1], // $extension
            
            ));
            if ($resourceUrl) {
                // $reference = (object) array_merge((array) $data, (array) $reference);
                $reference->url = $resourceUrl;
                $reference->filename= $source;
            }
        }
        $serial_reference = serialize($reference);
        return $serial_reference;
    }

    /**
     * Return file URL for external link.
     *
     * @inheritDocs
     */
    public function get_link($reference) {
        // $unpacked = $this->unpack_reference($reference);
        $unpacked = unserialize($reference);

        return $this->get_file_download_link($unpacked->url);
    }

    /**
     * Converts a URL received from dropbox API function 'shares' into URL that
     * can be used to download/access file directly
     *
     * @param string $sharedurl
     * @return string
     */
    protected function get_file_download_link($sharedurl) {
        rs_print("SHAREDURL: ". $sharedurl);
        $url = new \moodle_url($sharedurl);
        $url->param('dl', 1);

        return $url->out(false);
    }

    public function send_file($stored_file, $lifetime=86400 , $filter=0, $forcedownload=false, array $options = null) {

    // Example taken from repository_equella
        // $reference  = unserialize(base64_decode($stored_file->get_reference()));
        $reference = unserialize($stored_file->get_reference());
        // $url = $this->appendtoken($reference->url);
        $url = $reference->url;
        if ($url) {

            header('Location: ' . $url);
        } else {

            send_file_not_found();
        }
    }

    /**
     * Return the source information.
     *
     * The result of the function is stored in files.source field. It may be analysed
     * when the source file is lost or repository may use it to display human-readable
     * location of reference original.
     *
     * This method is called when file is picked for the first time only. When file
     * (either copy or a reference) is already in moodle and it is being picked
     * again to another file area (also as a copy or as a reference), the value of
     * files.source is copied.
     *
     * @inheritDocs
     */
    public function get_file_source_info($source) {
        global $USER;
        return 'ResourceSpace ('.fullname($USER).'): ' . $source;
    }

    public function get_reference_file_lifetime($ref) {
        
        return 60 * 60 * 24; // One day
    }   

    public function sync_individual_file(stored_file $storedfile) {
        
        return true;
    }

    public function get_reference_details($reference, $filestatus = 0) {
    // Example taken from repository_equella
        if (!$filestatus) {
            // $ref = unserialize(base64_decode($reference));
            $ref = unserialize($reference);
            //TODO: CHANGE THIS !!!!!!!!!!
            // $ref->filename = 'dummy';
            return $this->get_name(). ': '. $ref->filename;
        } else {
            return get_string('lostsource', 'repository', '');
        }
    }

    public function supported_filetypes() {
        
        return '*';
    }

    public function global_search() {
        
        return false;
    }

    public function supported_returntypes() {
        // return FILE_INTERNAL;
        // return FILE_INTERNAL | FILE_EXTERNAL | FILE_REFERENCE | FILE_CONTROLLED_LINK;
        // return FILE_INTERNAL |  FILE_EXTERNAL;
        return FILE_INTERNAL | FILE_EXTERNAL | FILE_REFERENCE;
        // return FILE_REFERENCE;
    }

    public static function get_type_option_names() {
        
        return array_merge(parent::get_type_option_names(), array('resourcespace_api_url', 'api_user', 'api_key', 'enable_help', 'enable_help_url'));
    }

    public static function type_config_form($mform, $classname = 'repository') {
        parent::type_config_form($mform);

        $mform->addElement('html', '<hr>');
        $mform->addElement('html', '<h2>Server settings</h2>');
        $mform->addElement('text', 'resourcespace_api_url', get_string('resourcespace_api_url', 'repository_resourcespace'));
        $mform->setType('resourcespace_api_url', PARAM_RAW_TRIMMED);
        $mform->addRule('resourcespace_api_url', 'required', 'required', null, 'client');
        $mform->addElement('static', null, '', get_string('resourcespace_api_url_help', 'repository_resourcespace'));

        $mform->addElement('text', 'api_user', get_string('api_user', 'repository_resourcespace'));
        $mform->setType('api_user', PARAM_RAW_TRIMMED);
        $mform->addRule('api_user', '', 'required', null, 'client');
        $mform->addElement('static', null, '', get_string('api_user_help', 'repository_resourcespace'));

        $mform->addElement('password', 'api_key', get_string('api_key', 'repository_resourcespace'));
        $mform->setType('api_key', PARAM_RAW_TRIMMED);
        $mform->addRule('api_key', '', 'required', null, 'client');
        $mform->addElement('static', null, '', get_string('api_key_help', 'repository_resourcespace'));

        $mform->addElement('html', '<hr>');
        $mform->addElement('html', '<h2>Miscellaneous settings</h2>');

        $mform->addElement('checkbox', 'enable_help', get_string('enable_help', 'repository_resourcespace'));
        $mform->addElement('static', null, '', get_string('enable_help_help', 'repository_resourcespace'));

        $mform->addElement('text', 'enable_help_url', get_string('enable_help_url', 'repository_resourcespace'));
        $mform->addElement('static', null, '', get_string('enable_help_url_help', 'repository_resourcespace'));
    }

    // Perform a search for collections and return a list of elements suitable for Moodle
    protected function do_search_collections($searchText = '') {
        $list = array();

        $collections = $this->make_api_request('search_public_collections', array(
            'param1' => $searchText,
            // 'param2' => 'c.name',
        ));

        if (is_array($collections)) {
            foreach ($collections as $collection) {
                $list[] = array(
                    'title' => $collection->name,
                    'path' => $collection->ref,
                    'date' => strtotime($collection->created),
                    'children' => array(),
                );
            }
        }

        return $list;
    }

    // Perform a search for resources and return a list of elements suitable for Moodle
    protected function do_search_resources($searchText = '') {
        $list = array();

        $resources = $this->make_api_request('search_get_previews', array(
            'param1' => $searchText, // $search
            // 'param3' => 'title',     // $order_by
            'param5' => '-1',        // $fetchrows
            'param8' => 'thm',       // $getsizes
        ));

        if (is_array($resources)) {
            foreach ($resources as $resource) {
                $list[] = array(
                    'title' => $resource->field8,
                    'thumbnail' => $resource->url_thm,
                    // Parsing the resourcespace ref and file extension as the filesource, because the
                    // resourcespace api does not return the actual source at this point.
                    'source' => sprintf('%s,%s', $resource->ref, $resource->file_extension),
                    'datemodified' => strtotime($resource->file_modified),
                );
            }
        }

        return $list;
    }

    // Make an API request
    protected function make_api_request($method, $queryData) {
        $queryData['user'] = $this->api_user;
        $queryData['function'] = $method;

        $query = http_build_query($queryData, '', '&');
        // Sign the request with the private key.
        $sign = hash("sha256", $this->api_key . $query);

        $requestUrl = "$this->resourcespace_api_url" . $query . "&sign=" . $sign;
        $response = file_get_contents($requestUrl);

        return json_decode($response);
    }
}
