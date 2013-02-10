<?php

/*
 * RackSummary Project
 *
 * This program can collect unit informations from different data sources
 * and creates a PDF output which displays the mounting positions of
 * units/systems in a rack.
 *
 * Copyright (c) 2011,2012,2013 Armin Pech, Duesseldorf, Germany.
 *
 *
 * This file is part of RackSummary.
 *
 * RackSummary is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or any later version.
 *
 * RackSummary is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RackSummary. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * Version: 2013-02-10-alpha
 * Last Update: 2013-02-10
 *
 * Website: https://github.com/pecharmin/racksummary
 *
 *
 * TODO:
 * # check some calculation routines
 * # write unit overlapping detection function
 * # check if font family is available
 */

// set include path to applications base dir, means ../
set_include_path('..'.PATH_SEPARATOR.get_include_path());

// load global used util functions
require_once('RackUtils.class.php');

// set font path
// TODO: set multiple pathes in FPDF_FONTPATH
//define('FPDF_FONTPATH','~/.fonts:/usr/share/fonts:./fpdf/font');

// load pdf creator api (http://fpdf.org/)
// License: free / there are no usage restrictions -- great!
require_once('fpdf/fpdf.php');


class RackPrinter extends RackUtils {
	/*** !!! DO NOT CHANGE THESE CLASS ATTRIBUTES OR FUNCTIONS BELOW !!! ***/
	/*** program control attributes -- NOT CHANGEABLE ***/
	// Application version number -- MUST NOT not be set on your own!
	private $program_version='2013-02-10-alpha';
	// Indicates if you want to get the output automatically.
	private $program_auto_output=true;
	// fpdf writer api saving point
	private $program_writer=null;
	// Module saving space
	private $program_modules=array();
	// Minumum font size
	private $program_min_font_size=4;
	// Scaling unit for inch to mm
	private $program_inch_mm=25.4;
	// Scaling unit for pt to mm
	private $program_pt_mm=0.3527;

	/*** internal & mostly static attributes ***/
	// Indicator for default mount count of a unit (1==3mh; 1==1u) / changeable
	// Rack printer expects unit heights in he/u counts by default.
	private $program_default_unit_height_mounts=3;
	// Available height formats & types for height parsing function.
	// array('<<height type>>'=><<height in mounting holes>>)
	// he==Hoeheneinheiten, u==unit, ru==rack units; be==Befestigungseinheiten, fu==fixing units, mh==mounting holes
	// ONLY THESE TYPES/THIS VARIBALE CAN BE CHANGED IF NEEDED!!!
	private $program_available_height_types=array('he'=>3, 'u'=>3, 'ru'=>3, 'be'=>1, 'fu'=>1, 'mh'=>1);
	// Dynamically on interpretation time set regular expression for height parsing function (see constructor).
	// You MUST !NOT! set this attribute manually!!!
	private $program_regexp_height_types='';
	// available output formats, values for dynamically pdf scaling
	private $program_output_scalar=array('a5'=>0.5, 'a4'=>1, 'a3'=>2);

	/*** rack and unit informations ***/
	// Name or identifier of the rack
	private $rack_name=null;
	// Description of rack
	private $rack_description=null;
	// height of rack in HE or BE value (see above)
	private $rack_height=null;
	// rack height text
	private $rack_height_description='rack units';
	// value in inch (most times 19)
	private $rack_width=null;
	// location where to find the rack
	private $rack_location=null;
	// rack site descriptions
	private $rack_front_description='front';
	private $rack_back_description='back';
	// rack site identifier
	private $rack_front_identifier='front';
	private $rack_back_identifier='back';
	// list of units/systems placed in this rack
	private $rack_units=array();

	/*** PDF meta information ***/
	// PDF author
	private $pdf_author=null;
	// PDF  title
	private $pdf_title=null;
	// PDF subject
	private $pdf_subject=null;
	// PDF keywords
	private $pdf_keywords=null;
	// PDF creator -- automatically set by this application;
	// you should not chance this
	private $pdf_creator=null;

	/*** pdf decoration ***/
	// margins of PDF in mm
	private $pdf_margins=null;
	// path to header image displayed in right upper corner
	private $pdf_header_image=null;
	// search in font path
	private $pdf_font_path=null;
	// used font family
	private $pdf_font_family='Arial';
	// default font size
	private $pdf_font_size=12;
	private $pdf_last_font_size=12;
	// rack and text output relations
	private $pdf_rack_width_percent=50;
	private $pdf_rack_description_width_percent=15;
	// attribute for scaling racks and units from inch to mm
	private $pdf_rack_scalar=1;
	// dynamic calculated width for unit description
	private $pdf_rack_description_width=null;
	// dynamic calculated width for unit comment
	private $pdf_rack_comment_width=null;
	// overall font size for rack description
	private $pdf_rack_description_general_font_size=null;
	// display separation lines of rack
	private $pdf_display_rack_side_separation=false;
	// separation of rack sides
	private $pdf_display_rack_side_separation_width=0.8;
	// rack side separation width in mm
	private $pdf_display_rack_side_sepration_line_width=0.4;
	// status attribute for displaying hole count or not
	private $pdf_display_hole_count=true;
	// hole count interval for rack sides
	private $pdf_hole_count_interval=5;
	// print out unit comments
	private $pdf_display_unit_comment=false;
	// status if you like to display last update string
	private $pdf_display_last_update=true;
	// customized last update prefix string
	private $pdf_last_update_string='Last update';
	// status varibale, if you want to display the creation time (in hh:mm format) of PDF
	private $pdf_display_last_update_time=false;

	/*** output options ***/
	// path to output file
	private $output_file=null;
	// selected output format: a4 etc.
	private $output_format=null;
	// destination where to send the document
	private $output_destination='F';


	/***** magic functions *****/
	public function __construct($verbose=false) {
		// set logging options
		$this->handle_verbose($verbose);
		// set pdf creator tag to program version
		$this->handle_pdf_creator('RackSummary '.$this->handle_version());
		// generate regexp of available height types for height parsing function parse_height()
		$this->program_regexp_height_types='(';
		foreach(array_keys($this->program_available_height_types) as $type) {
			$this->program_regexp_height_types.=$type.'|';
		}
		$this->program_regexp_height_types[strlen($this->program_regexp_height_types)-1]=')';
	}

	public function __destruct() {
		// only print rack if no error was detected and auto output is set to true
		if($this->handle_exit_code()===0) {
			if($this->program_auto_output===true) {
				$this->print_rack();
			}
		}
	}


	/***** local used functions *****/
	// *get* my version number
	public function handle_version() {
		return $this->program_version;
	}

	// get or create pdf writer object
	private function writer() {
		if(!$this->program_writer instanceof FPDF) {
			$this->program_writer=new FPDF('P', 'mm', strtoupper($this->handle_output_format()));
			if(!$this->program_writer instanceof FPDF) {
				$this->err_exit(20, 'pdf writer class FPDF could not be created');
			}
			$this->program_writer->SetDisplayMode('real', 'single');
		}
		return $this->program_writer;
	}

	// internal module handler
	private function handle_module($module=null) {
		$module=(String)$module;
		if(strlen($module)<1) {
			$this->err_exit(53, 'no module specified');
		}
		if(isset($this->program_modules[$module]) && $this->program_modules[$module] instanceof $module) {
			return $this->program_modules[$module];
		}
		require_once('modules/printer/'.$module.'.class.php');
		$this->program_modules[$module]=new $module($this->writer(), $this->handle_verbose());
		if(!isset($this->program_modules[$module]) || !($this->program_modules[$module] instanceof $module)) {
			$this->err_exit(54, 'object of module "'.$module.'" could not be created');
		}
		return $this->program_modules[$module];
	}

	// public module handler/interface
	public function module($module) {
		return $this->handle_module($module);
	}

	// parse height into integer, means as BE/MH
	private function parse_height($height) {
		if(strlen($height)<1) {
			$this->err_exit(21, 'wrong height found: "'.$height.'"');
		}
		if(is_numeric($height) && (int)$height>0) {
			return $height*$this->program_default_unit_height_mounts;
		}
		// prepare height string as array with number and unit
		$height=explode(' ', preg_replace('/^([0-9]*)'.$this->program_regexp_height_types.'/', '$1 $2', str_replace(' ', '', $height)));
		// is height type available?
		if(!isset($height[1]) || !isset($this->program_available_height_types[strtolower($height[1])])) {
			if(!isset($height[1])) {
				$height[1]="";
			}
			$this->err_exit(22, 'extracted height type "' . $height[1] . '" is not available');
		}
		// calculate height in BE
		$height=(int)$height[0]*$this->program_available_height_types[strtolower($height[1])];
		if($height>0) {
			return $height;
		}
		$this->err_exit(23, 'wrong height calculated: "'.$height.'"');
	}

	// *get* scaling for inch to mm
	public function handle_inch_mm() {
		return $this->program_inch_mm;
	}

	// *get* scaling for pt to mm
	public function handle_pt_mm() {
		return $this->program_pt_mm;
	}

	// scale font size of a string to a set width (in mm)
	public function get_scale_font_size($text="", $width_mm=0, $wished_font_size=0) {
		if(!strlen($text)>0 || $width_mm<=0 || $wished_font_size<=0) {
			return 0;
		}
		$fs_before=$this->writer()->FontSizePt;
		$ff_before=$this->writer()->FontFamily;
		$fy_before=$this->writer()->FontStyle;

		$this->writer()->SetFont($ff_before, $fy_before, $wished_font_size);
		$width_text=$this->writer()->GetStringWidth($text);
		$fs=$this->writer()->FontSizePt;
		$this->writer()->SetFont($ff_before, $fy_before, $fs_before);

		if($width_text>$width_mm) {
			return $fs*($width_mm/$width_text);
		}
		if($fs>$wished_font_size) {
			return $wished_font_size;
		}
		return $fs;
	}


	/***** attribute handler functions *****/
	/*** rack printer ***/
	public function handle_default_unit_height_mounts($value=null) {
		if($value!==null) {
			$value=(int)$value;
			if(!$value>0) {
				$this->err_exit(54, 'expected integer value greater than 0, but found "'.$value.'"');
			}
			$this->program_default_unit_height_mounts=int($value);
			return $this;
		}
		return $this->program_default_unit_height_mounts;
	}

	/*** rack information ***/
	// rack name
	public function handle_rack_name($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(24, 'no rack name found');
			}
			$this->rack_name=$value;
			return $this;
		}
		return $this->rack_name;
	}

	// rack description
	public function handle_rack_description($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(25, 'no rack description found');
			}
			$this->rack_description=$value;
			return $this;
		}
		return $this->rack_description;
	}

	// rack height in mount holes, units or something else
	public function handle_rack_height($value=null) {
		if($value!==null) {
			$this->rack_height=$this->parse_height($value);
			return $this;
		}
		return $this->rack_height;
	}

	// rack height description text
	public function handle_rack_height_description($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(26, 'no rack height description found');
			}
			$this->rack_height_description=$value;
			return $this;
		}
		return $this->rack_height_description;
	}

	// rack width in inch
	public function handle_rack_width($value=null) {
		if($value!==null) {
			$value=(int)$value;
			if(!$value>0) {
				$this->err_exit(27, 'wrong width "'.$value.'"; value must be an integer greater than 0');
			}
			$this->rack_width=$value;
			return $this;
		}
		return $this->rack_width;
	}

	// rack real location
	public function handle_rack_location($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(28, 'no location found');
			}
			$this->rack_location=$value;
			return $this;
		}
		return $this->rack_location;
	}

	// rack front side description text
	public function handle_rack_front_description($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(29, 'no rack description found');
			}
			$this->rack_front_description=$value;
			return $this;
		}
		return $this->rack_front_description;
	}

	// rack back side description text
	public function handle_rack_back_description($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(30, 'no rack description found');
			}
			$this->rack_back_description=$value;
			return $this;
		}
		return $this->rack_back_description;
	}

	// rack front identifier string
	public function handle_rack_front_identifier($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(51, 'no rack identifier found');
			}
			$this->rack_front_identifier=$value;
			return $this;
		}
		return $this->rack_front_identifier;
	}

	// rack back identifier string
	public function handle_rack_back_identifier($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(52, 'no rack identifier found');
			}
			$this->rack_back_identifier=$value;
			return $this;
		}
		return $this->rack_back_identifier;
	}

	/*** PDF information ***/
	public function handle_pdf_author($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(31, 'no pdf author found');
			}
			$this->pdf_author=$value;
			return $this;
		}
		return $this->pdf_author;
	}

	public function handle_pdf_title($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(32, 'no pdf title found');
			}
			$this->pdf_title=$value;
			return $this;
		}
		return $this->pdf_title;
	}

	public function handle_pdf_subject($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(33, 'no pdf subject found');
			}
			$this->pdf_subject=$value;
			return $this;
		}
		return $this->pdf_subject;
	}

	public function handle_pdf_keywords($value=null) {
		if($value!==null) {
			if(!(is_array($value) && count($value)>0)) {
				$this->err_exit(34, 'function expects an array with at least one element');
			}
			$keywords='';
			$count=0;
			foreach($value as $word) {
				if(!strlen($word)>0) {
					$this->err_exit(35, 'no string found in element #'.$count);
				}
				$keywords.=$word.', ';
				$count++;
			}
			$this->pdf_keywords=substr($keywords, 0, -2);
			return $this;
		}
		return $this->pdf_keywords;
	}

	// set PDF creator string to programs configuration
	private function handle_pdf_creator($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(36, 'no pdf creator found');
			}
			$this->pdf_creator=$value;
			return $this;
		}
		return $this->pdf_creator;
	}

	// PDF margins in mm
	public function handle_pdf_margins($value=null) {
		if($value!==null) {
			$value=(double)$value;
			if(!$value>0) {
				$this->err_exit(37, 'margins for PDF page must be an double value and greater or equal than 0, but "'.$value.'" found');
			}
			$this->pdf_margins=$value;
			return $this;
		}
		return $this->pdf_margins;
	}

	public function handle_pdf_header_image($value=null) {
		if($value!==null) {
			$this->pdf_header_image=$this->handle_file($value);
			return $this;
		}
		return $this->pdf_header_image;
	}

	public function handle_pdf_font_family($value=null) {
		if($value!==null) {
			// TODO3: check if font family is available
			$value=(string)$value;
			if(!strlen($value)>0) {
				$this->err_exit(38, 'no font family name found');
			}
			$this->pdf_font_family=$value;
			return $this;
		}
		return $this->pdf_font_family;
	}

	public function handle_pdf_font_size($value=null) {
		if($value!==null) {
			$value=(double)$value;
			if($value<$this->program_min_font_size) {
				$this->err_exit(39, 'please use a font size greater than '.$this->program_min_font_size);
			}
			$this->last_pdf_font_size=$this->pdf_font_size;
			$this->pdf_font_size=$value;
			return $this;
		}
		return $this->pdf_font_size;
	}

	// reset font size to last state
	public function reset_pdf_font_size() {
		$this->pdf_font_size=$this->last_pdf_font_size;
		return $this;
	}

	// scaling unit to compute rack printing
	private function handle_pdf_rack_scalar($value=null) {
		if($value!==null) {
			$value=(double)$value;
			if(!$value>0) {
				$this->err_exit(40, 'wrong size "'.$value.'" for rack scalar found, must be a double value greater than 0');
			}
			$this->pdf_rack_scalar=$value;
			return $this;
		}
		return $this->pdf_rack_scalar;
	}

	// separation width between rack sides
	public function handle_pdf_display_rack_side_separation($value=null) {
		if($value!==null) {
			$value=(boolean)$value;
			if($value===true) {
				$this->pdf_display_rack_side_separation=true;
			}
			else {
				$this->pdf_display_rack_side_separation=false;
			}
			return $this;
		}
		return $this->pdf_display_rack_side_separation;
	}

	public function handle_pdf_display_rack_side_separation_width($value=null) {
		if($value!==null) {
			$value=(int)$value;
			if($value<0) {
				$this->err_exit(0, '');
			}
			$this->pdf_display_rack_side_separation_width=$value;
			return $this;
		}
		return $this->pdf_display_rack_side_separation_width;
	}

	public function handle_pdf_display_rack_side_separation_line_width($value=null) {
		if($value!==null) {
			$value=(double)$value;
			if($value<0) {
				$this->err_exit(0, '');
			}
			$this->pdf_display_rack_side_separation_line_width=$value;
			return $this;
		}
		return $this->pdf_display_rack_side_separation_line_width;
	}

	public function handle_pdf_rack_min_width_percent($value=null) {
		if($value!==null) {
			$value=(double)$value;
			if(!$value) {
				$this->err_exit(0, '');
			}
			if($value+$this->handle_pdf_rack_description_max_width_percent()>100) {
				$this->err_exit(0, '');
			}
			$this->pdf_rack_width_percent=$value;
			return $this;
		}
		return $this->pdf_rack_width_percent;
	}

	public function handle_pdf_rack_description_max_width_percent($value=null) {
		if($value!==null) {
			$value=(double)$value;
			if(!$value) {
				$this->err_exit(0, '');
			}
			if($value+$this->handle_pdf_rack_min_width_percent()>100) {
				$this->err_exit(0, '');
			}
			$this->pdf_rack_description_width_percent=$value;
			return $this;
		}
		return $this->pdf_rack_description_width_percent;
	}

	private function handle_pdf_rack_description_width($value=null) {
		if($value!==null) {
			$value=(double)$value;
			if(!$value>0) {
				$this->err_exit(41, 'wrong size for rack description width: "'.$value.'", must be a double value greater than 0');
			}
			$this->pdf_rack_description_width=$value;
			return $this;
		}
		return $this->pdf_rack_description_width;
	}

	private function handle_pdf_rack_comment_width($value=null) {
		if($value!==null) {
			$value=(double)$value;
			if(!$value>0) {
				$this->err_exit(0, '');
			}
			$this->pdf_rack_comment_width=$value;
			return $this;
		}
		return $this->pdf_rack_comment_width;
	}

	public function handle_pdf_display_hole_count($value=null) {
		if($value!==null) {
			$value=(boolean)$value;
			if($value===true) {
				$this->pdf_display_hole_count=true;
			}
			else {
				$this->pdf_display_hole_count=false;
			}
			return $this;
		}
		return $this->pdf_display_hole_count;
	}

	public function handle_hole_count_interval($value=null) {
		if($value!==null) {
			$value=(int)$value;
			if(!$value>0) {
				$this->err_exit(56, 'hole count interval must be an integer value greater than 0');
			}
			$this->pdf_hole_count_interval=$value;
			return $this;
		}
		return $this->pdf_hole_count_interval;
	}

	public function handle_pdf_display_unit_comment($value=null) {
		if($value!==null) {
			$value=(boolean)$value;
			if($value===true) {
				$this->pdf_display_unit_comment=true;
			}
			else {
				$this->pdf_display_unit_comment=false;
			}
		}
		return $this->pdf_display_unit_comment;
	}

	// handle timezone for date printing
	public function handle_timezone($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!@date_default_timezone_set($value)) {
				$this->err_exit(55, 'Timezone with identifier "'.$value.'" is not available');
			}
			return $this;
		}
		return date_default_timezone_get();
	}

	public function handle_pdf_display_last_update($value=null) {
		if($value!==null) {
			$value=(boolean)$value;
			if($value===true) {
				$this->pdf_display_last_update=true;
			}
			else {
				$this->pdf_display_last_update=false;
			}
			return $this;
		}
		return $this->pdf_display_last_update;
	}

	public function handle_pdf_last_update_string($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!strlen($value)) {
				$this->err_exit(42, 'wrong string found for last update string: "'.$value.'"');
			}
			$this->pdf_last_update_string=$value;
			return $this;
		}
		return $this->pdf_last_update_string;
	}

	public function handle_pdf_display_last_update_time($value=null) {
		if($value!==null) {
			$value=(boolean)$value;
			if($value===true) {
				$this->pdf_display_last_update_time=true;
			}
			else {
				$this->pdf_display_last_update_time=false;
			}
			return $this;
		}
		return $this->pdf_display_last_update_time;
	}


	/*** output options ***/
	public function handle_output_file($value=null) {
		if($value!==null) {
			$this->output_file=$this->handle_file($value, true);
			return $this;
		}
		return $this->output_file;
	}

	public function handle_output_format($value=null) {
		if($value!==null) {
			$value=(string)$value;
			if(!array_key_exists(strtolower($value), $this->program_output_scalar)) {
				$this->err_exit(43, 'output format "'.$value.'" is not available');
			}
			$this->output_format=$value;
			return $this;
		}
		return $this->output_format;
	}

	public function handle_output_destination($value=null) {
		if($value===null) {
			return $this->output_destination;
		}
		$value=(string)$value;
		if($value=='i' || $value=='I' || $value=='inline') {
			$this->output_destination='I';
		}
		elseif($value=='d' || $value=='D' || $value=='download') {
			$this->output_destination='D';
		}
		elseif($value=='f' || $value=='F' || $value=='file') {
			$this->output_destination='F';
		}
		else {
			$this->err_exit(44, 'output destination "'.$value.'" is not supported');
		}
		return $this;
	}

	public function handle_output_destination_inline($value=false) {
		if($value!==true) {
			if($this->handle_output_destination()=='I') {
				return true;
			}
			return false;
		}
		return $this->handle_output_destination('I');
	}

	public function handle_output_destination_download($value=false) {
		if($value!==true) {
			if($this->handle_output_destination()=='D') {
				return true;
			}
			return false;
		}
		return $this->handle_output_destination('D');
	}

	public function handle_output_destination_file($value=false) {
		if($value!==true) {
			if($this->handle_output_destination()=='F') {
				return true;
			}
			return false;
		}
		return $this->handle_output_destination('F');
	}


	/*** other public used functions ***/
	// do no auto output to file when program ends
	public function disable_auto_output() {
		$this->program_auto_output=false;
		return $this;
	}


	/*** unit management for rack sites ***/
	// get an/all added unit/s
	public function get_unit($name=null) {
		if($name===null) {
			return $this->rack_units;
		}
		$name=(string)$name;
		if(!isset($this->_rack_units[$name])) {
			$this->err_exit(45, 'unit with name "'.$name.'" could not be found');
		}
		return $this->rack_units[$name];
	}

	// add a unit/system to a rack (site)
	public function add_unit($unit) {
		if(is_array($unit)) {
			if(!count($unit)>0) {
				$this->err_exit(46, 'array must have at least one element');
			}
			foreach($unit as $current_unit) {
				$this->add_unit($current_unit);
			}
			return $this;
		}
		if(!$unit instanceof RackUnit) {
			$this->err_exit(47, 'function expects data of object "RackUnit"');
		}
		if(isset($this->rack_units[$unit->handle_name()])) {
			$this->err_exit(48, 'another unit with name "'.$unit->handle_name().'" had already been added to unit dataset');
		}
		$this->rack_units+=array($unit->handle_name()=>$unit);
		return $this;
	}

	// check if unit placed in rack and delete it
	public function delete_unit($name) {
		$name=(string)$name;
		if(array_key_exists($name, $this->rack_units)) {
			unset($this->rack_units[$name]);
		}
		return $this;
	}


	/*** printer and positioning functions ***/
	// print unit on a rack site
	private function print_unit($rack_margin_top, $rack_margin_left, $unit) {
		// prepare static values
		$unit_position_top=$rack_margin_top+($unit->handle_position()-1)*0.58*$this->handle_pdf_rack_scalar();
		$unit_position_left=$rack_margin_left+0.5851*$this->handle_pdf_rack_scalar()+0.1;
		$unit_height=$this->parse_height($unit->handle_height())*0.58*$this->handle_pdf_rack_scalar();
		$unit_width=$this->handle_rack_width()*$this->handle_pdf_rack_scalar()-0.26;
		$rack_width_mm=($this->handle_rack_width()+0.58*2)*$this->handle_pdf_rack_scalar();

		// prepare pdf writer - set line width and background color for rectangle
		$this->writer()->SetLineWidth(0.14);
		$this->writer()->SetDrawColor(0);
		if($unit->handle_color_red()!==null) {
			if($unit->handle_color_green()!==null && $unit->handle_color_blue()!==null) {
				$this->writer()->SetFillColor($unit->handle_color_red(), $unit->handle_color_green(), $unit->handle_color_blue());
			}
			else {
				$this->writer()->SetFillColor($unit->handle_color_red());
			}
		}
		else {
			$this->writer()->SetFillColor(220);
		}
		// print unit name
		$font_size_unit_name=$this->get_scale_font_size($unit->handle_name()." ", $this->handle_pdf_rack_description_width(), $this->handle_pdf_rack_scalar()*3.1);
		$this->writer()->SetFont($this->handle_pdf_font_family(), '', $font_size_unit_name);
		$this->writer()->Text($rack_margin_left-$this->writer()->GetStringWidth($unit->handle_name()." "), $unit_position_top+$this->handle_pdf_rack_scalar()*0.352+$unit_height/2, $unit->handle_name());

		// print unit to rack
		$this->writer()->Rect($unit_position_left, $unit_position_top, $unit_width+0.014*$this->handle_pdf_rack_scalar(), $unit_height, 'F');
		$this->writer()->Line($rack_margin_left, $unit_position_top, $rack_margin_left+$rack_width_mm, $unit_position_top);
		$this->writer()->Line($rack_margin_left, $unit_position_top+$unit_height, $rack_margin_left+$rack_width_mm, $unit_position_top+$unit_height);

		// Optional unit decoration
		if($this->module('RackCoverPrinter')->handle_activation()===true) {
			$this->module('RackCoverPrinter')->print_unit_cover($unit->handle_type(), $unit_position_top, $unit_position_left, $unit_height, $unit_width);
		}

		// Add unit comment
		if($this->handle_pdf_display_unit_comment() && strlen($unit->handle_comment())>0) {
			// Get width of hole counts
			$this->writer()->SetFont($this->handle_pdf_font_family(), '', $this->handle_pdf_rack_scalar()*2);
			$hole_space=$this->writer()->GetStringWidth('000');

			// Set comment font size to prefered size of unit name
			$this->writer()->SetFont($this->handle_pdf_font_family(), '', $font_size_unit_name);
			// Get scaled font size for whole online comment to comment width
			$font_size_comment=$this->get_scale_font_size($unit->handle_comment(), $this->handle_pdf_rack_comment_width(), $this->handle_pdf_rack_scalar()*3.1);
			$this->writer()->SetFont($this->handle_pdf_font_family(), '', $font_size_comment);

			// TODO: move status value to check font_size_comment against to class attributes
			// Check if unit comment must&can be split into multiple lines
			if(	$font_size_comment<$font_size_unit_name ||
				$this->writer()->GetStringWidth($unit->handle_comment())>$this->handle_pdf_rack_comment_width() ||
				$font_size_comment<5.5
			) {
				// Get count of possible comment lines
				$comment_line_count=intval($this->parse_height($unit->handle_height())/2);
				if($comment_line_count<2) {
					$comment_line_count=2;
				}

				// Prepare comment sting for splitting
				$unit_comment=$unit->handle_comment();
				$comment_width=strlen($unit_comment);

				// handle comment string without whitespaces
				if(!strstr($unit_comment, ' ')) {
					$comment_array=str_split($unit_comment, ceil($comment_width/$comment_line_count));
				}
				else {
					$comment_array=explode(" ", $unit_comment);
				}

				// split comment string into multiple lines on whitespaces of most equal length
				$output_array=array();
				$ci=0;
				$output_array[0]=$comment_array[0];
				array_shift($comment_array);
				foreach($comment_array as $word) {
					if(strlen($output_array[$ci])<$comment_width/$comment_line_count) {
						$output_array[$ci]=$output_array[$ci] . " " . $word;
					}
					else {
						$ci++;
						$output_array[$ci]=$word;
					}
				}

				// get longest comment string
				$longest_comment_string="";
				foreach($output_array as $words) {
					if(strlen($words)>strlen($longest_comment_string)) {
						$longest_comment_string=$words;
					}
				}

				// Scale font size of comment lines for longest comment string, if height of comment lines are lower unit height
				$font_size_comment=$this->get_scale_font_size($longest_comment_string, $this->handle_pdf_rack_comment_width(), $this->handle_pdf_rack_scalar()*3.1);
				$comment_height=(count($output_array)*$font_size_comment+$this->handle_pdf_rack_scalar()*0.352)*$this->handle_pt_mm();
				if($comment_height>$unit_height) {
					$font_size_comment=$this->get_scale_font_size($longest_comment_string, $this->handle_pdf_rack_comment_width(), $font_size_comment*$unit_height/$comment_height);
				}

				// Set options for comment output
				$this->writer()->SetFont($this->handle_pdf_font_family(), 'I', $font_size_comment);
				$unit_comment_start=$unit_position_top+$this->handle_pdf_rack_scalar()*0.352+($unit_height-(count($output_array)-1)*$this->writer()->FontSize)/2;

				// Print comment lines to output
				foreach($output_array as $comment) {
					$this->writer()->Text($rack_margin_left+$rack_width_mm+1.75+$hole_space, $unit_comment_start, $comment);
					$unit_comment_start+=$this->writer()->FontSize;
				}
			}
			else {
				// Set options for comment output
				$unit_comment=$unit->handle_comment();
				$unit_comment_start=$unit_position_top+$this->handle_pdf_rack_scalar()*0.352+$unit_height/2;

				// Print comment lines to output
				$this->writer()->SetFont($this->handle_pdf_font_family(), 'I', $font_size_comment);
				$this->writer()->Text($rack_margin_left+$rack_width_mm+1.75+$hole_space, $unit_comment_start, $unit->handle_comment());
			}
		}

		return $this;
	}


	// print front or back of rack
	private function print_site($description, $margin_top, $margin_left) {
		$rack_width_mm=($this->handle_rack_width()+0.58*2)*$this->handle_pdf_rack_scalar();

		// print site description/name
		$this->writer()->SetFont($this->handle_pdf_font_family(), '', $this->get_scale_font_size($description, $rack_width_mm, $this->handle_pdf_rack_scalar()*3.1));
		$this->writer()->SetX($margin_left);
		$this->writer()->Cell($rack_width_mm, 0, $description, 0, 0, 'C');
		// draw rack
		$this->writer()->SetLineWidth(0.2);
		$this->writer()->Line($margin_left, $margin_top, $margin_left+$rack_width_mm, $margin_top);
		$this->writer()->Line($margin_left, $margin_top, $margin_left, $margin_top+$this->handle_rack_height()*$this->handle_pdf_rack_scalar()*0.58);
		$this->writer()->Line($margin_left+0.58*$this->handle_pdf_rack_scalar(), $margin_top, $margin_left+0.58*$this->handle_pdf_rack_scalar(), $margin_top+$this->handle_rack_height()*$this->handle_pdf_rack_scalar()*0.58);
		$this->writer()->Line($margin_left+$rack_width_mm, $margin_top, $margin_left+$rack_width_mm, $margin_top+$this->handle_rack_height()*$this->handle_pdf_rack_scalar()*0.58);
		$this->writer()->Line($margin_left+($this->handle_rack_width()+0.58)*$this->handle_pdf_rack_scalar(), $margin_top, $margin_left+($this->handle_rack_width()+0.58)*$this->handle_pdf_rack_scalar(), $margin_top+$this->handle_rack_height()*$this->handle_pdf_rack_scalar()*0.58);
		// prepare environment for drawing holes
		$margin_top+=$this->handle_pdf_rack_scalar()*0.165;
		$hole_count=0;
		$this->writer()->SetDrawColor(0);
		$this->writer()->SetFillColor(0);
		$this->writer()->SetLineWidth(0.14);
		// set font size for hole number markers
		$this->writer()->SetFont($this->handle_pdf_font_family(), '', $this->handle_pdf_rack_scalar()*2);
		// draw holes
		while($this->handle_rack_height()>$hole_count) {
			$this->writer()->Rect($margin_left+$this->handle_pdf_rack_scalar()*0.165, $margin_top+$hole_count*0.58*$this->handle_pdf_rack_scalar(), 0.25*$this->handle_pdf_rack_scalar(), 0.25*$this->handle_pdf_rack_scalar(), 'F');
			$this->writer()->Rect($margin_left+($this->handle_rack_width()+0.745)*$this->handle_pdf_rack_scalar(), $margin_top+$hole_count*0.58*$this->handle_pdf_rack_scalar(), 0.25*$this->handle_pdf_rack_scalar(), 0.25*$this->handle_pdf_rack_scalar(), 'F');
			// mark hole with number
			if($this->handle_pdf_display_hole_count() && ($hole_count+1)%$this->handle_hole_count_interval()==0) {
				$this->writer()->Text($margin_left+$rack_width_mm+0.75, $margin_top+($hole_count+0.67)*0.58*$this->handle_pdf_rack_scalar(), $hole_count+1);
			}
			$hole_count++;
		}
		// draw rack base
		$this->writer()->SetLineWidth(0.2);
		$this->writer()->Rect($margin_left, $margin_top-0.3+$this->handle_rack_height()*$this->handle_pdf_rack_scalar()*0.58, $rack_width_mm, $this->handle_pdf_rack_scalar()*2.2);
		// wind back to origin font size
		$this->writer()->SetFont($this->handle_pdf_font_family(), '', $this->handle_pdf_font_size());
		return $this;
	}

	// print rack to PDF
	public function print_rack() {
		// set meta data (true means in UTF8)
		$this->writer()->SetAuthor($this->handle_pdf_author(), true);
		$this->writer()->SetTitle($this->handle_pdf_title(), true);
		$this->writer()->SetSubject($this->handle_pdf_subject(), true);
		$this->writer()->SetCreator($this->handle_pdf_creator(), true);
		$this->writer()->SetKeywords($this->handle_pdf_keywords(), true);

		// prepare page
		$this->writer()->SetMargins($this->handle_pdf_margins(), $this->handle_pdf_margins());
		$this->writer()->SetFont($this->handle_pdf_font_family(), 'B', $this->handle_pdf_font_size());
		$this->writer()->AddPage();

		// print header (name, location, image, etc.)
		$this->writer()->SetXY($this->handle_pdf_margins(), $this->handle_pdf_margins());
		$this->writer()->Write(0, $this->handle_rack_name());
		$this->writer()->Ln($this->handle_pdf_font_size()/2);
		$this->writer()->SetFont($this->handle_pdf_font_family(), '', $this->handle_pdf_font_size());
		$this->writer()->Write(0, $this->handle_rack_location());
		$this->writer()->Ln($this->handle_pdf_font_size()/2);
		if(strlen($this->handle_rack_height_description())>0) {
			$this->writer()->Write(0, $this->handle_rack_height_description().': '.$this->handle_rack_height()/3);
		}
		$this->writer()->Ln($this->handle_pdf_font_size()*0.8);

		// print image
		if(strlen($this->handle_pdf_header_image())>0) {
			// php.net: "This function does not require the GD image library." -- yeah :)
			$max_image_height=5*4;
			$image_height=getimagesize($this->handle_pdf_header_image());
			$image_width=$image_height[0]*0.3;
			$image_height=$image_height[1]*0.3;
			if($image_height>$max_image_height) {
				$image_width=$image_width*$max_image_height/$image_height;
				$image_height=$max_image_height;
			}
			$this->writer()->Image($this->handle_pdf_header_image(), (int)$this->writer()->CurPageSize[0]-(int)$this->writer()->lMargin-$image_width, $this->handle_pdf_margins()*0.8, $image_width, $image_height);
		}

		// print last update = today
		if($this->handle_pdf_display_last_update()) {
			$last_update='d.m.Y';
			if($this->handle_pdf_display_last_update_time()) {
				$last_update.=' (H:i)';
			}
			$this->writer()->Text((int)$this->writer()->lMargin, (int)$this->writer()->CurPageSize[1]-(int)$this->writer()->tMargin, $this->handle_pdf_last_update_string().': '.date($last_update));
		}

		// calculate rack width/rack scalar (inch -> mm) before to get height of rack units
		$rack_inch_scalar=($this->writer()->CurPageSize[1]-$this->handle_pdf_margins()*2-9*$this->handle_pdf_font_size()*$this->handle_pt_mm())/($this->handle_rack_height()+3)*1.72;
		// TODO: fix rack_scalar_to_percent_relation
		$rack_scalar_to_percent_relation=$rack_inch_scalar*$this->handle_rack_width()/($this->writer()->CurPageSize[0]-$this->handle_pdf_margins()*2-$this->handle_pdf_display_rack_side_separation_width())/100*$this->handle_pdf_rack_min_width_percent();
		if($rack_scalar_to_percent_relation>1) {
			$rack_inch_scalar/=$rack_scalar_to_percent_relation;
		}
		else {
			//$this->handle_pdf_rack_min_width_percent($this->handle_pdf_rack_min_width_percent()*$rack_scalar_to_percent_relation);
			true;
		}
		$this->handle_pdf_rack_scalar($rack_inch_scalar);

		// calculate description width
		$longest_string=0;
		$this->writer()->SetFont($this->handle_pdf_font_family(), '', $this->handle_pdf_rack_scalar()*3.1);
		foreach($this->get_unit() as $unit) {
			$string_width=$this->writer()->GetStringWidth($unit->handle_name()." ");
			if($string_width>$longest_string) {
				$longest_string=$string_width;
			}
		}
		$this->reset_pdf_font_size();
		$description_width=($this->writer()->CurPageSize[0]-$this->writer()->lMargin*2-1.3*2-$this->handle_pdf_display_rack_side_separation_width()-0.745*$this->handle_pdf_rack_scalar())*$this->handle_pdf_rack_description_max_width_percent()/100/2;


		// get printable width
		$this->writer()->SetFont($this->handle_pdf_font_family(), '', $this->handle_pdf_rack_scalar()*2);
		$hole_space=$this->writer()->GetStringWidth('000');

		// TODO: check this again
		$printable_width=$this->writer()->CurPageSize[0]-$this->writer()->lMargin*2-$this->handle_pdf_display_rack_side_separation_width()-0.58*2-0.745*$this->handle_pdf_rack_scalar()*2-3.5-$hole_space*2-0.25*$this->handle_pdf_rack_scalar()*4;

		// set description width in mm
		if($description_width>$longest_string) {
			$this->handle_pdf_rack_description_max_width_percent($this->handle_pdf_rack_description_max_width_percent()*$longest_string/$description_width);
		}
		$this->handle_pdf_rack_description_width($longest_string);

		// set comment width in mm
		$this->handle_pdf_rack_comment_width($printable_width/2-$this->handle_pdf_rack_description_width()-($this->handle_rack_width()+0.58*2)*$this->handle_pdf_rack_scalar());

		// set rack positions
		$rack_margin_top=$this->writer()->GetY()+$this->handle_pdf_font_size()/2;
		$rack_front_margin_left=$this->writer()->lMargin+$this->handle_pdf_rack_description_width();
		$rack_back_margin_left=$this->writer()->CurPageSize[0]/2+$this->handle_pdf_display_rack_side_separation_width()/2+$this->handle_pdf_rack_description_width();

		// print rack side separation
		if($this->handle_pdf_display_rack_side_separation()) {
			$sep_line_width=0.4; // TODO: move to class attributes & add function
			$this->writer()->SetLineWidth($this->handle_pdf_display_rack_side_separation_line_width());
			$this->writer()->Line(
				($this->writer()->CurPageSize[0]+$this->handle_pdf_display_rack_side_separation_width())/2-$sep_line_width/2,
				$this->writer()->GetY(),
				($this->writer()->CurPageSize[0]+$this->handle_pdf_display_rack_side_separation_width())/2-$sep_line_width/2,
				$this->writer()->GetY()+$this->handle_pdf_font_size()/2+$this->handle_rack_height()*$this->handle_pdf_rack_scalar()*0.58+$this->handle_pdf_rack_scalar()*2.2
			);
		}

		// print rack sides
		$this->print_site($this->handle_rack_front_description(), $rack_margin_top, $rack_front_margin_left);
		$this->print_site($this->handle_rack_back_description(), $rack_margin_top, $rack_back_margin_left);

		// now print the units...
		$this->writer()->SetFont($this->handle_pdf_font_family(), '', $this->handle_pdf_rack_scalar()*3.1);
		foreach($this->get_unit() as $unit) {
			// of front site
			if($unit->handle_site()==$this->handle_rack_front_identifier()) {
				$this->print_unit($rack_margin_top, $rack_front_margin_left, $unit);
			}
			// or back site
			elseif($unit->handle_site()==$this->handle_rack_back_identifier()) {
				$this->print_unit($rack_margin_top, $rack_back_margin_left, $unit);
			}
			// oops -- see you next try
			else {
				$this->err_exit(49, 'rack site identifier of unit "'.$unit->handle_name().'" is not correct, expecting "'.$this->handle_rack_front_identifier().'" (front site) or "'.$this->handle_rack_back_identifier().'" (back site) but found "'.$unit->handle_site().'"');
			}
		}
		// output to file
		if($this->handle_output_destination_file() && is_null($this->handle_output_file())) {
			$this->err_exit(50, 'output destination is "file" but no output file was set');
		}
		$this->writer()->Output($this->handle_output_file(), $this->handle_output_destination());
		return $this;
	}
}

?>
