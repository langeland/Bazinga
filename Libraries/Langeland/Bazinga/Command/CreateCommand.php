<?php

namespace Langeland\Bazinga\Command;

class CreateCommand extends \Langeland\Bazinga\Command\AbstractCommand {

	public function __construct() {
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('create')
			->setDescription('Create a new vhost');
	}

	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {
		$this->input = $input;
		$this->output = $output;

		$helper = $this->getHelper('question');

		$configuration = array();
		/***************************************************************************************************************
		 *
		 **************************************************************************************************************/
		$question = new \Symfony\Component\Console\Question\Question('Input main domain name. (no http://): ');
		$question->setValidator(function ($answer) {
			if ($answer == '') {
				throw new \RuntimeException('Domain name cannot be empty');
			}
			return $answer;
		});

		$configuration['domain'] = trim($helper->ask($input, $output, $question));
		$configuration['directory'] = implode('.',array_reverse(explode('.',$configuration['domain'])));


		/***************************************************************************************************************
		 *
		 **************************************************************************************************************/
		$question = new \Symfony\Component\Console\Question\Question('Input name of the installation. This will be used to create user and group (lowercase letters and numbers only): ');
		$question->setValidator(function ($answer) {
			if ($answer == '') {
				throw new \RuntimeException('Installation name cannot be empty');
			}
			if(in_array($answer . '_web', $this->getSystemUsers())){
				throw new \RuntimeException('User exists');
			}

			if(in_array($answer . '_grp', $this->getSystemGroups())){
				throw new \RuntimeException('Group exists');
			}

			return $answer;
		});

		$configuration['name'] = trim($helper->ask($input, $output, $question));

		$configuration['user'] = $configuration['name'] . '_web';
		$configuration['group'] = $configuration['name'] . '_grp';



		/***************************************************************************************************************
		 *
		 **************************************************************************************************************/
		$pseudoGroups = array('langeland', 'danquah');
		$question = new \Symfony\Component\Console\Question\ChoiceQuestion(
			'Please select a pseudo group for the domain: ',
			$pseudoGroups
		);
		$question->setErrorMessage('Pseudo group %s is invalid.');
		$configuration['pseudoGroups'] = $helper->ask($input, $output, $question);


		/***************************************************************************************************************
		 *
		 **************************************************************************************************************/
		print_r($configuration);

	}


	private function getSystemUsers(){
		$process = new \Symfony\Component\Process\Process('cat /etc/passwd | cut -d: -f1');
		$process->run();
		foreach(explode("\n", $process->getOutput()) AS $line) {
			if($line != '' && substr($line, 0, 1) != '#'){
				$users[] = $line;
			}
		}
		return $users;
	}

	private function getSystemGroups(){
		$process = new \Symfony\Component\Process\Process('cat /etc/group | cut -d: -f1');
		$process->run();
		foreach(explode("\n", $process->getOutput()) AS $line) {
			if($line != '' && substr($line, 0, 1) != '#'){
				$groups[] = $line;
			}
		}
		return $groups;
	}

}

