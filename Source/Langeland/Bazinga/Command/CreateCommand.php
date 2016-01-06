<?php

namespace Langeland\Bazinga\Command;

class CreateCommand extends \Langeland\Bazinga\Command\AbstractCommand {

	protected $virtualHostConfiguration = array();

	protected function configure() {
		$this
			->setName('create')
			->setDescription('Create a new vhost');
	}

	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {
		$this->input = $input;
		$this->output = $output;

		if (posix_getuid() > 0) {
			$output->writeln('You must run this as root.. Your id is: ' . posix_getuid());
			die();
		}

		$helper = $this->getHelper('question');

		/***************************************************************************************************************
		 *
		 **************************************************************************************************************/
		$question = new \Symfony\Component\Console\Question\Question('Input name of the installation. This will be used to create user and group (lowercase letters and numbers only): ');
		$question->setValidator(function ($answer) {
			if ($answer == '') {
				throw new \RuntimeException('Installation name cannot be empty');
			}
			if (in_array($answer . '_web', $this->getSystemUsers())) {
				throw new \RuntimeException('User exists');
			}

			if (in_array($answer . '_grp', $this->getSystemGroups())) {
				throw new \RuntimeException('Group exists');
			}

			return $answer;
		});

		$this->virtualHostConfiguration['name'] = trim($helper->ask($input, $output, $question));
		$this->virtualHostConfiguration['user'] = $this->virtualHostConfiguration['name'] . '_web';
		$this->virtualHostConfiguration['group'] = $this->virtualHostConfiguration['name'] . '_grp';

		/***************************************************************************************************************
		 *
		 **************************************************************************************************************/
		$question = new \Symfony\Component\Console\Question\ChoiceQuestion(
			'Please select a pseudo group for the domain: ',
			$this->configuration['pseudo_groups']
		);
		$question->setErrorMessage('Pseudo group %s is invalid.');
		$this->virtualHostConfiguration['pseudoGroup'] = $helper->ask($input, $output, $question);

		/***************************************************************************************************************
		 *
		 **************************************************************************************************************/
		print_r($this->virtualHostConfiguration);

		print_r(array(
			'rootDirectory' => '/home/hostroot/' . $this->virtualHostConfiguration['pseudoGroup'] . '/' . $this->virtualHostConfiguration['name'],
			'create user' => $this->virtualHostConfiguration['user'],
			'create group' => $this->virtualHostConfiguration['group']
		));

		$this->create();
	}

	private function createDirectory($pathname, $mode = 0777, $user = NULL, $group = NULL) {

		if (!mkdir($pathname, 0770, TRUE)) {
			throw new \Exception('Failed to create folder: ' . $pathname);
		}

		if (!chown($pathname, $user)) {
			throw new \Exception('Failed to set owner for folder: ' . $pathname);
		}
		if (!chgrp($pathname, $group)) {
			throw new \Exception('Failed to set group for folder: ' . $pathname);
		}

	}

	private function getSystemUsers() {
		$process = new \Symfony\Component\Process\Process('cat /etc/passwd | cut -d: -f1');
		$process->run();
		foreach (explode("\n", $process->getOutput()) AS $line) {
			if ($line != '' && substr($line, 0, 1) != '#') {
				$users[] = $line;
			}
		}
		return $users;
	}

	private function getSystemGroups() {
		$process = new \Symfony\Component\Process\Process('cat /etc/group | cut -d: -f1');
		$process->run();
		foreach (explode("\n", $process->getOutput()) AS $line) {
			if ($line != '' && substr($line, 0, 1) != '#') {
				$groups[] = $line;
			}
		}
		return $groups;
	}

	private function create() {
		$this->taskCreateDirectories();
		$this->taskCreateFpmFile();
		$this->taskCreateVirtualHostFile();

		$this->taskCreateSystemUserAndGroup();
		$this->taskEnableSite();
		$this->taskRestartApache();
	}

	private function taskCreateDirectories() {
		$this->output->writeln('Creating Directories');

		$pseudoGroupDirectory = $this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'];
		$virtualHostDirectory = $pseudoGroupDirectory . '/' . $this->virtualHostConfiguration['name'];

		if (!is_dir($this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'])) {
			die('Missing pseudoGroup dir');
		}

		$this->createDirectory($virtualHostDirectory, 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/', 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/logs/', 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/conf/', 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/sessions/', 0770, $this->virtualHostConfiguration['user'], $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/sockets/', 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/htdocs/', 0770, $this->virtualHostConfiguration['user'], $this->virtualHostConfiguration['group']);
	}

	private function taskCreateVirtualHostFile() {
		$this->output->writeln('Creating VirtualHost File');
		$template = new \Langeland\Bazinga\Service\TemplateService(__DIR__ . '/../../../../Resources/VirtualHost.template');
		$template->setVar('installationName', $this->virtualHostConfiguration['name']);
		$template->setVar('installationRoot', $this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'] . '/' . $this->virtualHostConfiguration['name']);
		$fileContent = $template->render();

		$file = $this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'] . '/' . $this->virtualHostConfiguration['name'] . '/system/conf/virtualhost.conf';

		file_put_contents($file, $fileContent);

		symlink($file, $this->configuration['directories']['sites_available'] . '/' . $this->virtualHostConfiguration['name'] . '.conf');
	}

	private function taskCreateFpmFile() {
		$this->output->writeln('Creating FPM File');
		$template = new \Langeland\Bazinga\Service\TemplateService(__DIR__ . '/../../../../Resources/fpm.template');
		$template->setVar('installationName', $this->virtualHostConfiguration['name']);
		$template->setVar('installationRoot', $this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'] . '/' . $this->virtualHostConfiguration['name']);
		$template->setVar('user', $this->virtualHostConfiguration['user']);
		$template->setVar('group', $this->virtualHostConfiguration['group']);
		$fileContent = $template->render();

		$file = $this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'] . '/' . $this->virtualHostConfiguration['name'] . '/system/conf/fpm.pool';

		file_put_contents($file, $fileContent);

		symlink($file, $this->configuration['directories']['fpm_pool'] . '/' . $this->virtualHostConfiguration['name'] . '.pool');
	}

	private function taskCreateSystemUserAndGroup() {
		$this->output->writeln('Creating Syetem user and group (Not implemented)');

	}

	private function taskEnableSite() {
		$this->output->writeln('Enabling apache site (Not implemented)');

	}

	private function taskRestartApache() {
		$this->output->writeln('Creating Restarting apache (Not implemented)');

	}

}

