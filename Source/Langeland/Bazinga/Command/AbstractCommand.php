<?php

namespace Langeland\Bazinga\Command;

class AbstractCommand extends \Symfony\Component\Console\Command\Command {

	/**
	 * @var \Symfony\Component\Console\Input\InputInterface
	 */
	protected $input;

	/**
	 * @var \Symfony\Component\Console\Output\OutputInterface
	 */
	protected $output;

	protected $configuration;

	public function __construct($configuration) {
		$this->configuration = $configuration;
		parent::__construct();
	}

}
