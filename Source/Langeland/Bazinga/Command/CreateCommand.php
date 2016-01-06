<?php

namespace Langeland\Bazinga\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Exception\ProcessFailedException;

class CreateCommand extends \Langeland\Bazinga\Command\AbstractCommand {

	protected $virtualHostConfiguration = array();

	protected function configure() {
		$this
			->setName('create')
			->setDescription('Create a new vhost')
			->addOption('name', NULL, InputOption::VALUE_OPTIONAL)
			->addOption('group', NULL, InputOption::VALUE_OPTIONAL);
	}

	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {
		$this->input = $input;
		$this->output = $output;

		if (posix_getuid() > 0) {
			$output->writeln('You must run this as root.. Your id is: ' . posix_getuid());
			die();
		}

		$helper = $this->getHelper('question');

		if (!empty($input->getOption('name'))) {
			$this->virtualHostConfiguration['name'] = trim($input->getOption('name'));
		} else {
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
		}
		/***************************************************************************************************************
		 *
		 **************************************************************************************************************/
		$this->virtualHostConfiguration['web_user'] = $this->virtualHostConfiguration['name'] . '_web';
		$this->virtualHostConfiguration['login_user'] = $this->virtualHostConfiguration['name'] . '_login';
		$this->virtualHostConfiguration['group'] = $this->virtualHostConfiguration['name'] . '_grp';

		// Get the pseudo-group.
		if (!empty($input->getOption('group'))) {
			$this->virtualHostConfiguration['pseudoGroup'] = trim($input->getOption('group'));
		} else {
			$question = new \Symfony\Component\Console\Question\ChoiceQuestion(
				'Please select a pseudo group for the domain: ',
				$this->configuration['pseudo_groups']
			);
			$question->setErrorMessage('Pseudo group %s is invalid.');
			$this->virtualHostConfiguration['pseudoGroup'] = $helper->ask($input, $output, $question);
		}

		$pseudoGroupDirectory = $this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'];
		$virtualHostDirectory = $pseudoGroupDirectory . '/' . $this->virtualHostConfiguration['name'];
		$this->virtualHostConfiguration['login_homedir'] = $virtualHostDirectory;
		$this->virtualHostConfiguration['web_homedir'] = $virtualHostDirectory . '/htdocs/';

		$this->create();
	}

	private function createDirectory($pathname, $mode, $user, $group) {

		if (!mkdir($pathname, $mode, TRUE)) {
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
		$this->taskCreateSystemUserAndGroup();
		$this->taskCreateDirectories();
		$this->taskCreateFpmFile();
		$this->taskCreateVirtualHostFile();

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

		$this->createDirectory($virtualHostDirectory, 0755, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/', 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/logs/', 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/conf/', 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/system/sessions/', 0770, $this->virtualHostConfiguration['web_user'], $this->virtualHostConfiguration['group']);
		//$this->createDirectory($virtualHostDirectory . '/system/sockets/', 0750, 'root', $this->virtualHostConfiguration['group']);
		$this->createDirectory($virtualHostDirectory . '/htdocs/', 0775, $this->virtualHostConfiguration['web_user'], $this->virtualHostConfiguration['group']);
	}

	private function taskCreateVirtualHostFile() {
		$this->output->writeln('Creating VirtualHost File');
		$template = new \Langeland\Bazinga\Service\TemplateService(__DIR__ . '/../../../../Resources/VirtualHost.template');
		$template->setVar('installationName', $this->virtualHostConfiguration['name']);
		$template->setVar('installationRoot', $this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'] . '/' . $this->virtualHostConfiguration['name']);
		$template->setVar('fpmSocketPath', $this->configuration['directories']['fpm_sockets']);
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
		$template->setVar('user', $this->virtualHostConfiguration['web_user']);
		$template->setVar('group', $this->virtualHostConfiguration['group']);
		$template->setVar('fpmSocketPath', $this->configuration['directories']['fpm_sockets']);
		$fileContent = $template->render();

		$file = $this->configuration['directories']['hostroot'] . '/' . $this->virtualHostConfiguration['pseudoGroup'] . '/' . $this->virtualHostConfiguration['name'] . '/system/conf/fpm.pool';

		file_put_contents($file, $fileContent);

		symlink($file, $this->configuration['directories']['fpm_pool'] . '/' . $this->virtualHostConfiguration['name'] . '.pool');
	}

	/**
	 * Adds a web and login system-user.
	 *
	 * Both are in the <name>_grp group
	 *
	 * _web has writeaccess to the webroot and nothing else
	 * _login has write-access to
	 */
	private function taskCreateSystemUserAndGroup() {
		// Create the web-user.
		$group_esc = escapeshellarg($this->virtualHostConfiguration['group']);
		$process = new \Symfony\Component\Process\Process('addgroup --force-badname ' . $group_esc);
		try {
			$process->mustRun();
		} catch (ProcessFailedException $e) {
			$this->output->writeln('Could not create the group ' . $this->virtualHostConfiguration['group'] . ': ' . $e->getMessage());
			throw $e;
		}

		$web_homedir_esc = escapeshellarg($this->virtualHostConfiguration['web_homedir']);
		$web_user_esc = escapeshellarg($this->virtualHostConfiguration['web_user']);

		$process = new \Symfony\Component\Process\Process('adduser --force-badname --no-create-home --home ' . $web_homedir_esc . ' --shell /bin/false --disabled-login --gecos \'\' --ingroup ' . $group_esc . ' ' . $web_user_esc);
		try {
			$process->mustRun();
		} catch (ProcessFailedException $e) {
			$this->output->writeln('Unable to create user ' . $this->virtualHostConfiguration['web_user'] . ': ' . $e->getMessage());
			throw $e;
		}

		// Create the login-user.
		$login_homedir_esc = escapeshellarg($this->virtualHostConfiguration['login_homedir']);
		$login_user_esc = escapeshellarg($this->virtualHostConfiguration['login_user']);
		$process = new \Symfony\Component\Process\Process('adduser --force-badname --no-create-home --home ' . $login_homedir_esc . ' --disabled-login --gecos \'\' --ingroup ' . $group_esc . ' ' . $login_user_esc);
		try {
			$process->mustRun();
		} catch (ProcessFailedException $e) {
			$this->output->writeln('Unable to create user ' . $this->virtualHostConfiguration['login_user'] . ': ' . $e->getMessage());
			throw $e;
		}
	}

	private function taskEnableSite() {
		$this->output->writeln('Enabling apache site (Not implemented)');

	}

	private function taskRestartApache() {
		$this->output->writeln('Creating Restarting apache (Not implemented)');

	}

}

