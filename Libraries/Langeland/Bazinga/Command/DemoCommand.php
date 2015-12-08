<?php

namespace Langeland\Bazinga\Command;

class DemoCommand extends \Langeland\Bazinga\Command\AbstractCommand {

	public function __construct() {
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('demo')
			->setDescription('Just a demo commant');
	}

	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {
		$this->input = $input;
		$this->output = $output;

	}
}

