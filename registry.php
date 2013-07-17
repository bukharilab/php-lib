<?php
/**
 *
 * LICENSE
 *
 * Copyright (C) 2013 Michel Dumontier. All rights reserved.
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * 
 *
 * @package    phplib
 * @copyright  Copyright (c) 2013 Michel Dumontier
 * @license    http://www.opensource.org/licenses/bsd-license.php
 * @version    $Id$
 */
 
/**
 * A resource/dataset registry.
 *
 * @package    php-lib
 * @copyright  Copyright (c) 2009-2013 Michel Dumontier
 * @license    http://www.opensource.org/licenses/bsd-license.php
 */
class CRegistry
{
	/** a local registry */
	private $registry = null;
	/** the prefix and uri map to the preferred prefix */
	private $map = null;
	/** the remote location of the registry */
	private $remote_registry_url = 'https://docs.google.com/spreadsheet/pub?key=0AmzqhEUDpIPvdFR0UFhDUTZJdnNYdnJwdHdvNVlJR1E&single=true&gid=0&output=csv';
	/** the local registry directory */
	private $local_registry_dir = '';
	/** the local version of the registry */
	private $local_registry_file = 'registry.csv';
	/** the local registry filepath */
	private $local_registry_filepath = null;
	/** the registry cache time in days */
	private $cache_time = 1;
	/** the action to take if registry doesn't contain namespace */
	private $unregistered_ns_action = 'continue';
	/** a list of unmatched prefix requests */
	private $no_match = null;
	/** a list of the prioritized uri schemes */
	private $uri_schemes = array ("original","bio2rdf","identifiers.org");
	/** a list of resources that must use the original provider uri */
	private $default_uri_schemes = array ("xsd","rdf","rdfs","owl","void","dc");
	
	public function __construct()
	{
		
	}
	
	/** initialize the registry; fetch if need be and parse */
	public function initialize()
	{
		if(!isset($this->registry)) {
			$this->fetchRegistry();
			$this->parseRegistry();
		}
		return $this;
	}
	
	
	/** set the URL for the remote registry */
	public function setRemoteRegistryURL($url)
	{
		if($url != null && $url != '') $this->remote_registry_url = $url;
		else throw new InvalidArgumentException("null or empty remote registry URL");
		return $this;
	}
	
	/** get the remote registry URL */
	public function getRemoteRegistryURL()
	{
		return $this->remote_registry_url;
	}
	
	/** set the filepath for the locally-cached registry */
	public function setLocalRegistry($dir)
	{
		if($dir != null && $dir != '') {
			$this->local_registry_dir = $dir;
			$this->local_registry_filepath = $dir.$this->local_registry_file;
		}
		else throw new InvalidArgumentException("null or empty local registry file location");
		return $this;
	}
	
	/** get the location of the locally-cached registry file */
	public function getLocalRegistryFilename()
	{
		return $this->local_registry_filepath;
	}
	
	/** set the amount of time (in days) to use a local copy of the registry since its last update; 0 for no update */
	public function setCacheTime($cache_time_in_days)
	{
		$this->cache_time = (int) $cache_time_in_days;
		return $this;
	}
	/** get the amount of time (in days) to continue using a local copy of the registry since its last update */
	public function getCacheTime()
	{
		return $this->cache_time;
	}
	
	/** what to do in case the namespace isn't found in the registry */
	public function setUnregisteredNSAction($action)
	{
		$this->unregistered_ns_action = $action;
		return $this;
	}
	
	/** get the ns fail value */
	public function getUnregisteredNSAction()
	{
		return $this->unregistered_ns_action;
	}
	
	public function setURISchemePriority(array $schemes)
	{
		$this->uri_schemes = $schemes;
		return $this;
	}
	public function getURISchemePriority()
	{
		return $this->uri_schemes;
	}

	/** add a prefix to the no match list */
	protected function addNoMatch($prefix)
	{
		if(!isset($this->no_match[$prefix])) {
			trigger_error("Unable to map $prefix", E_USER_WARNING);
			$this->no_match[$prefix] = 1;
		} else {
			$this->no_match[$prefix] ++;
		}
		return $this;
	}
	/** get the no match list */
	public function getNoMatchList()
	{
		return $this->no_match;
	}
	
	/** print the no match list */
	public function printNoMatchList()
	{
		$a = $this->getNoMatchList();
		if(isset($a)) {
			foreach($a AS $prefix => $n) {
				echo "NOTICE: Unable to find namespace $prefix in registry: $n occurrences".PHP_EOL;
			}
		}
	}
	/** clear the no match list */
	public function clearNoMatchList()
	{
		$this->no_match = null;
	}
	
	/** download/refresh the registry */
	public function fetchRegistry()
	{
		trigger_error("BEGIN",E_USER_NOTICE);
		$download = true;

		if($this->getLocalRegistryFilename() == null) $this->setLocalRegistry("");
		
		if(file_exists($this->getLocalRegistryFilename())) {
			// check whether the file is current
			$file = new DateTime(date("Y-m-d",filemtime($this->getLocalRegistryFilename())));
			$now = new DateTime(date("Y-m-d", time()));
			$interval = date_diff($now,$file);
			$days = $interval->format('%a');
			if($this->cache_time == 0) {
				trigger_error("Registry is out of date by ".($days-$this->cache_time)." days", E_USER_WARNING);
				$download = false;
			} else if($this->cache_time <= $days) {
				trigger_error("Registry is up to date.", E_USER_NOTICE);
				$download = false;
			} else {
				trigger_error("Registry is set to be updated",E_USER_NOTICE);
				$download = true;
			}
		} else {
			trigger_error("no local copy of registry.", E_USER_WARNING);
			$download = true;
		}
		
		if($download === true) {
			echo("Downloading dataset registry").PHP_EOL;
			$buf = file_get_contents($this->remote_registry_url);
			if($buf === FALSE) {
				trigger_error("Unable to get remote registry file", E_USER_ERROR);
			}
			$ret = file_put_contents($this->getLocalRegistryFilename(), $buf);
			if($ret === FALSE) {
				trigger_error("Unable to save local registry file ".$this->getLocalRegistryFilename(), E_USER_ERROR);
				return FALSE;
			}
			trigger_error("Download complete", E_USER_NOTICE);
		} 
		trigger_error("END",E_USER_NOTICE);
		return TRUE;
	}
	
	/** parse the registry from the local cache */
	protected function parseRegistry()
	{
	
	$keys = array(
"preferredPrefix", // [0] => Preferred Prefix 
"alternatePrefix", // [1] => Alt-prefix */         
"providerURI",     // [2] => Provider Base URI
"alternateURI",    // [3] => Alternative Base URI
"miriam",          // [4] => MIRIAM
"bioportal",       // [5] => BioPortal Ontology ID 
"datahub",         // [6] => thedatahub
"abbreviation",    // [7] => Abbreviation
"title",           // [8] => Title
"description",     // [9] => Description
"pubmed",          // [10] => PubMed ID
"organization",    // [11] => Organization
"type",            // [12] => Type (warehouse, dataset or terminology)
"keywords",        // [13] => Keywords
"homepage",        // [14] => Homepage
"homepage_up",     // [15] => homepage still available?
"subnamespace",    // [16] => sub-namespace in dataset
"partOfCollection", // [17] => part of collection
"license",         // [18] => License URL
"licenseText",     // [19] => License Text
"rights",          // [20] => Rights
"id_regex",        // [21] => ID regex
"example_id",      // [22] => ExampleID
"html_template",   // [23] => Provider HTML URL
"empty",           // [24] =>
"miriam_notes",    // [25] => MIRIAM curator notes
"miriam_coverage", // [26] => MIRIAM coverage
"miriam_updates",  // [27] => updates
);

		if (($fp = fopen($this->getLocalRegistryFilename(), "r")) === FALSE) {
			trigger_error("Unable to open ".$this->getLocalRegistryFilename(), E_USER_ERROR);
			return FALSE;
		}
		$header = fgetcsv($fp);
		while (($r = fgetcsv($fp)) !== FALSE) {
			$prefix = $r[0];
			$e = null;
			$a = array_slice($r,0,24);
			foreach($a AS $i => $v) {
				$k = $keys[$i];
				$e[$k] = $v;
			}
			
			$this->registry[$prefix] = $e;
			$uri = $r[2];
			if($uri != '') {
				$this->registry[$prefix]['provider-uri'] = $uri;
				$this->map[$uri] = $prefix;
			}
			$this->registry[$prefix]['bio2rdf-uri'] = "http://bio2rdf.org/".$prefix.":";
			$this->registry[$prefix.'_vocabulary']['bio2rdf-uri'] = "http://bio2rdf.org/".$prefix."_vocabulary:";
			$this->registry[$prefix.'_resource']['bio2rdf-uri'] = "http://bio2rdf.org/".$prefix."_resource:";
			if($r[4]) {
				$this->registry[$prefix]['identifiers.org-uri'] = "http://identifiers.org/";
			}
			
			// add alternative prefixes to map
			$this->map[$this->normalizePrefix($prefix)] = $prefix;
			foreach( explode(",",$r[1]) AS $syn) {
				if(trim($syn) == '') continue;
				$syn = $this->normalizePrefix(preg_replace("/\([^\)]+/","",$syn));
				$this->map[$syn] = $prefix;
			}
			
			// add alternative URIs to map
			if($uri && $r[3] != '') {
				foreach( explode(",",$r[3]) AS $alt_uri) {
					$this->map[$alt_uri] = $uri;
				}
			}	
		}
	}

	/** get the registry entry by namespace */
	public function getEntry($ns)
	{
		if(!$this->isPrefix($ns)) {
			trigger_error("Unable to retrieve $ns",E_USER_ERROR);
			return null;
		} 		
		return $this->registry[$ns];
	}
	
	/** normalize the prefix for the prefix map */
	public static function normalizePrefix($prefix)
	{
		return preg_replace("/[^a-z0-9]/","",strtolower(trim($prefix)));
	}
	
	/** ask whether the prefix is in the registry */
	public function isPrefix($prefix)
	{
		$this->initialize();
		if(isset($this->registry[$prefix])) {
			return TRUE;
		}
		return FALSE;
	}
	
	/** get the preferred prefix */
	public function getPreferredPrefix($prefix)
	{
		$this->initialize();
		if($this->isPrefix($prefix)) {
			return $prefix;
		}
		// otherwise try the map
		$myprefix = $this->normalizePrefix($prefix);
		if(isset($this->map[$myprefix])) {
			return $this->map[$myprefix];
		}
		
		// record the non-match
		$this->addNoMatch($prefix);
		// otherwise die if need be
		if($this->getUnregisteredNSAction() == "die") {
			trigger_error("Unable to map $prefix in $qname; i was told to die here.", E_USER_ERROR);
			exit();
		}	
		return FALSE;
	}
		
	/** Parse a prefixed name (e.g. GI:12345) into its constitutive parts
	 * 
	 * @param string $name the potentially prefixed name
	 * @param string $prefix the identified prefix, if found
	 * @param string $identifier the identifier without prefix
	 * @param string $delimiter the delimiter that separates the prefix from the identifier
	 * @return true on execution
	 */
	public static function parseQName($name, &$prefix, &$identifier, $delimiter = ':')
	{
		$a = explode($delimiter,$name,2);
		if(count($a) == 1) {
			// there is no prefix
			$prefix = null;
			$prefix = trim($name);
		} else {
			$prefix = strtolower(trim($a[0]));
			$identifier = trim($a[1]);
		}
		return TRUE;
	}
	
	/** Map a qualified name (e.g. ko:KO12345) into a preferred qname (e.g. kegg:KO12345)
	 * 
	 * @param string $qname 		the qualified name
	 * @param string $delimiter 	the delimiter to be used
	 * @param boolean $ignore_errors	return qname if unable to map or null if ignore_errors is set to false (default)
	 * @return string				the qualified name if  using a preferred prefix:identifier, or null if ignore error is false
	 */
	public function mapQName($qname,$delimiter=':')
	{
		$this->initialize();
		$ns = '';
		$id='';
		
		// parse the qname
		$this->parseQName($qname,$ns,$id,$delimiter);
		// find the preferred prefix
		$prefix = $this->getPreferredPrefix($ns);
		if($prefix !== FALSE) {
			// trigger_error("Found $prefix in $qname",E_USER_NOTICE);
			return "$prefix:$id";
		}

		// otherwise return the input
		return "$ns:$id";		
	}
	
	public function setDefaultURISchemes($ns)
	{
		if(is_array($ns)) $this->default_uri_schemes = $ns;
		else {
			$this->default_uri_schemes[] = $ns;
		}
		return $this;
	}
	public function getDefaultURISchemes()
	{
		return $this->default_uri_schemes;
	}

	/** get the fully qualified URI for a qname and a uri scheme 
	 * 
	 * @param string $qname the qualified name
	 * @param string $scheme the uri-scheme
	 * @return string the fully qualified URI
	 */
	public function getFQURI($qname, $scheme = null)
	{		
		$this->initialize();
		$qname = $this->mapQName($qname);
		$this->parseQName($qname,$ns,$id);
		
		// exclude the defaults 
		if(in_array($ns,$this->getDefaultURISchemes())) {
			return $this->registry[$ns]['provider-uri'].$id;
		}
			
		if(isset($scheme)) {
			return $this->registry[$ns][$scheme].$id;
		} else {
			// otherwise go through the scheme priority
			foreach($this->getURISchemePriority() AS $scheme) {
				if(isset($this->registry[$ns][$scheme])) {					
					return $this->registry[$ns][$scheme].$id;
				}
			}
		}
		
		
		// we have problem.
		if($this->getUnregisteredNSAction() == "fail") {
			trigger_error("Unable to find a uri for $scheme",E_USER_ERROR);
			return null;
		}
		// use the bio2rdf uri
		return $this->getBio2RDF_URI($qname);
	}
	
	/** 
	 * get a Bio2RDF for the given qname 
	 * 
	 * @param string $qname the qualified name
	 * @return string The Bio2RDF URI
	 */
	public function getBio2RDF_URI($qname)
	{
		$this->initialize();
		return "http://bio2rdf.org/$qname";
	}
	/** 
	 * generate a Bio2RDF URI using the preferred qname
	 * 
	 * @param string $qname the qualified name
	 * @return string The Bio2RDF URI
	 */
	public function getMappedBio2RDF_URI($qname)
	{
		$this->initialize();
		return "http://bio2rdf.org/".$this->mapQName($qname);
	}
	
	public function getIdentifiersDotOrg_URI($qname)
	{
		$this->initialize();
		$qname = $this->mapQName($qname);
		$this->parseQName($qname,$ns,$id);
		$e = $this->getEntry($ns);
		if(isset($e->miriam) && ($e->miriam != '')) {
			//@todo
		}
		
	}
}


	

