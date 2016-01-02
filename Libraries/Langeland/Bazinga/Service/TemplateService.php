<?php
namespace Langeland\Bazinga\Service;

class TemplateService {

	private $template;

	private $vars;

	public function __construct($templatePathAndName) {
		$this->setTemplatePathAndName($templatePathAndName);
	}

	public function setTemplatePathAndName($templatePathAndName) {
		if (file_exists($templatePathAndName)) {
			$this->template = file_get_contents($templatePathAndName);
		} else {
			$this->template = $templatePathAndName;
		}
	}

	public function setVar($var, $content) {
		$this->vars[$var] = $content;
	}

	public function render() {
		$this->replaceAll();
		return $this->template;
	}

	private function replaceAll() {
		foreach ($this->vars as $var => $content) {
			$this->template = str_replace('{' . $var . '}', $content, $this->template);
		}
	}
}