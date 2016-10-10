<?php

require_once 'thirdparty/spyc/spyc.php';

class SimpleYamlExporter extends Controller {

	static $allowed_actions = array(
		'index',
		'export',
		'SimpleForm'
	);

	function init() {
		parent::init();

		// Basic access check.
		$canAccess = (Director::isDev() || Director::is_cli() || Permission::check("ADMIN"));
		if(!$canAccess) return Security::permissionFailure($this);
	}


	function SimpleForm() {
		$fields = new FieldList();
		// Display available yml files so we can re-export easily.
		$ymlDest = BASE_PATH.'/'.TestDataController::get_data_dir(); 
		$existingFiles = scandir($ymlDest);
		$ymlFiles = array();
		foreach ($existingFiles as $file) {
			if (preg_match("/.*\.yml/", $file)) {
				$ymlFiles[$file] = $file;
			}
		}
		if ($ymlFiles) {
			$fields->push($drop = new DropdownField('Reexport', 'Reexport to file (will override any other setting): ', $ymlFiles, '', null));
			$drop->setEmptyString('choose file');
		}

		// Get the list of available DataObjects
		$dataObjectNames = ClassInfo::subclassesFor('DataObject');
		unset($dataObjectNames['DataObject']);
		sort($dataObjectNames);

		foreach ($dataObjectNames as $dataObjectName) {
			// Skip test only classes.
			$class = singleton($dataObjectName);
			if($class instanceof TestOnly) continue;

			// Skip testdata internal class
			if ($class instanceof TestDataTag) continue;

			// 	Create a checkbox for including this object in the export file
			$count = $class::get()->Count();
			$fields->push($class = new CheckboxField("Class[$dataObjectName]", $dataObjectName." ($count)"));
			$class->addExtraClass('class-field');

			// 	Create an ID range selection input
			$fields->push($range = new TextField("Range[$dataObjectName]", ''));
			$range->addExtraClass('range-field');
		}
		// Create the "traverse relations" option - whether it should automatically include relation objects even if not explicitly ticked.
		$fields->push(new CheckboxField('TraverseRelations', 'Traverse relations (implicitly includes objects, for example pulls Groups for Members): ', 1));

		// Create the option to include real files.
		$path = BASE_PATH.'/'.TestDataController::get_data_dir();
		$fields->push(new CheckboxField('IncludeFiles', "Copy real files (into {$path}files)", 0));

		// Create file name input field
		$fields->push(new TextField('FileName', 'Name of the output YML file: ', 'output.yml'));
		$fields->push(
			DropdownField::create(
				'SubsiteID',
				'Subsite: ', 
				Subsite::get()->map('ID', 'Title')
			)
		);
	
		// Create actions for the form
		$actions = new FieldList(new FormAction("export", "Export"));

		$form = new Form($this, "SimpleForm", $fields, $actions);
		$form->setFormAction(Director::baseURL().'dev/data/simple/SimpleYamlExporter/SimpleForm');

		return $form;
	}

	function export($data, $form) {
		$ymlDest = BASE_PATH.'/'.TestDataController::get_data_dir();
		@mkdir($ymlDest);
		
		if($data['SubsiteID']){
			$_GET['SubsiteID'] = $data['SubsiteID'];
		}

		echo '<pre>';
		print_r($data);

		$output = array();

		foreach($data['Class'] as $topClass => $val) {
			$results = $topClass::get();
			if(isset($data['Range'][$topClass])){
				$results = $topClass::get()->byIDs(explode(',',$data['Range'][$topClass]));
			}
			$output[$topClass] = array();
			foreach($results as $res){
				if ($topClass != $res->ClassName) continue;
				$output[$topClass][$topClass.$res->ID] = $res->toMap( );

				$oneRelations = array_merge(
					($relation = $res->has_one()) ? $relation : array(),
					($relation = $res->belongs_to()) ? $relation : array()
				);
				//  Check if the objects are already processed into buckets (or queued for processing)
				foreach ($oneRelations as $relation => $class) {
					if($res->$relation()->ID){
						$output[$topClass][$topClass.$res->ID][$relation.'ID'] = $res->$relation()->ID;
					}
				}

				$manyRelations = array_merge(
					// ($relation = $res->has_many()) ? $relation : array(),
					($relation = $res->many_many()) ? $relation : array() // Includes belongs_many_many
				);
				foreach ($manyRelations as $relation => $class) {
					if($res->$relation()->count()){
						$output[$topClass][$topClass.$res->ID][$relation]= implode(',',$res->$relation()->column('ID'));
					}

				}

			}
		}
		
		file_put_contents($ymlDest.$data['FileName'], Spyc::YAMLDump($output));

	}
}