<?php

namespace app\console\controllers;
use Yii;
use app\models\GatekeepProject;
use app\helpers\ProjectCreator;
use app\helpers\XMLHelper;

/**
 * Command line controller
 * e.g. php yii gate/keeper test
 **/

class GateController extends \yii\console\Controller {
	public $verbose;

	/**
	 * @function actionGatekeep
	 * reads the project xml file specified and runs gatekeeper
	 **/
	public function actionKeeper($command_name = 'help', $url = '') {
		$commands = $this->getCommands();
		if (array_key_exists($command_name, $commands)) {
			$fn = $commands[$command_name]['fn'];
			$this->$fn($url);
		} else {
			// default assumes this is a project, and scans it
			return $this->scanProject($command_name);
		}
	}

	public function scanProject($site_name) : int {
		$gatekeeper = new GatekeepProject($this->verbose);
		return $gatekeeper->run($site_name);
	}

	public function options($actionID) {
		return [ 'verbose' ];
	}

	public function optionAliases() {
      return ['v' => 'verbose'];
   }

	/**
	 * @function createProject
	 * Creates a simple project
	 * prompt for name, title, url?
	 **/

	protected function createProject() {
		new ProjectCreator();
	}

	protected function getCommands() {
		$commands = [
			'list'	=> [
							'summary' => 'List all available projects',
							'info' => 'Lists all projects in data folder. No parameters.',
							'fn' => 'listProjects',
						],
			'scan'	=> [
							'summary' => 'Scan a named project site',
							'info' => 'Scans a site and builds a csv file of results.' . PHP_EOL . 'Use --verbose to display details.',
							'fn' => 'scanProject',
						],
			'help'	=> [
							'summary' => 'Display more detailed help on a command',
							'info' => 'Display a list of commands, or detailed help on a specific command.',
							'fn' => 'showHelp'
						],
			'create'	=> [
							'summary' => 'Create a new project',
							'info' => 'Create a new project. You will be prompted to provide the name, url and a description',
							'fn' => 'createProject',
						],
		];
		return $commands;
	}

	protected function showHelp(string $command_name = '') {
		// colors from https://stackoverflow.com/questions/5947742/how-to-change-the-output-color-of-echo-in-linux
		$orange = "\033[0;33m";
		$light_green = "\033[1;32m";
		$cyan = "\033[1;36m";

		$nc = "\033[0m";

		if ($command_name) {
			$commands = $this->getCommands();
			if (array_key_exists($command_name, $commands)) {
				$summary = $commands[$command_name]['summary'];
				$info = $commands[$command_name]['info'];
				echo $cyan . $summary . $nc . PHP_EOL;
				echo $info . PHP_EOL;
			} else {
				echo "Command '$command_name' not found." . PHP_EOL;
			}
		} else {
			// Show a summary of all commands

			echo "Welcome to " . $cyan . '[Gate'. '/' . 'keeper]' . $nc . PHP_EOL . "Please use one of these commands:" . PHP_EOL;
			$space_to_pos = 20;
			foreach ($this->getCommands() as $command_name => $command_ar) {
				$num_spaces = $space_to_pos - strlen($command_name);
				echo $light_green . $command_name . $nc . ": " . $this->getSpaces($num_spaces) . $command_ar['summary'] . PHP_EOL;
			}
		}
	}

	protected function getSpaces($num) {
		$spaces = '';
		for ($i = 0; $i < $num; $i++) {
			$spaces .= ' ';
		}
		return $spaces;
	}

	protected function listProjects() {
		$projects_folder = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'data';
		$project_files = scandir($projects_folder);
		$project_files = array_diff($project_files, array('.', '..'));

		$light_green = "\033[1;32m";
		$cyan = "\033[1;36m";
		$nc = "\033[0m";

		if ($project_files) {
			echo "Projects available:" . PHP_EOL;
			$title_pos = $this->findLongestProjectNameSize($project_files) + 2;

			foreach ($project_files as $project_file) {
				$project_name = str_replace('.xml', '', $project_file);
				echo $project_name;
				$num_spaces = $title_pos - strlen($project_name);
				echo $this->getSpaces($num_spaces);

				$gk = new GatekeepProject(true);
				if ($gk->loadProject($projects_folder . DIRECTORY_SEPARATOR . $project_file)) {
					$title = $gk->getTitle();
				} else {
					$title = "failed to load";
				}
				if ($title) {
					echo ' - ' . $cyan . $title . $nc;
				}
				echo PHP_EOL;
			}
		}
		echo PHP_EOL . 'To run a project use ' . $light_green . 'php yii gate/keeper scan {xml_filename}' . $nc . PHP_EOL;
	}

	protected function findLongestProjectNameSize($projects) {
		$longest_size = 0;
		foreach ($projects as $project_file) {
			$project_name = str_replace('.xml', '', $project_file);
			if ($project_name && strlen($project_name) > $longest_size) {
				$longest_size = strlen($project_name);
			}
		}
		return $longest_size;

	}
}