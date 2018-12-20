<?php

namespace Harmony\Flex\IO;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class ConsoleIO
 *
 * @package Harmony\Flex\IO
 */
class ConsoleIO extends SymfonyStyle implements IOInterface
{

    /** @var array $authentications */
    protected $authentications = [];

    /** @var InputInterface $input */
    protected $input;

    /** @var OutputInterface $output */
    protected $output;

    /** @var HelperSet $helperSet */
    protected $helperSet;

    /** @var float $startTime */
    protected $startTime;

    /** @var array<int, int> */
    protected $verbosityMap;

    /** @var string $lastMessage */
    protected $lastMessage;

    /** @var string $lastMessageErr */
    protected $lastMessageErr;

    /**
     * ConsoleIO constructor.
     *
     * @param IOInterface $io
     */
    public function __construct(IOInterface $io)
    {
        $refObject     = new \ReflectionObject($io);
        $inputProperty = $refObject->getProperty('input');
        $inputProperty->setAccessible(true);
        $outputProperty = $refObject->getProperty('output');
        $outputProperty->setAccessible(true);
        $helperProperty = $refObject->getProperty('helperSet');
        $helperProperty->setAccessible(true);

        $this->input     = $inputProperty->getValue($io);
        $this->output    = $outputProperty->getValue($io);
        $this->helperSet = $helperProperty->getValue($io);
        parent::__construct($this->input, $this->output);
    }

    /**
     * Get all authentication information entered.
     *
     * @return array The map of authentication data
     */
    public function getAuthentications()
    {
        return $this->authentications;
    }

    /**
     * Verify if the repository has a authentication information.
     *
     * @param string $repositoryName The unique name of repository
     *
     * @return bool
     */
    public function hasAuthentication($repositoryName)
    {
        return isset($this->authentications[$repositoryName]);
    }

    /**
     * Get the username and password of repository.
     *
     * @param string $repositoryName The unique name of repository
     *
     * @return array The 'username' and 'password'
     */
    public function getAuthentication($repositoryName)
    {
        if (isset($this->authentications[$repositoryName])) {
            return $this->authentications[$repositoryName];
        }

        return ['username' => null, 'password' => null];
    }

    /**
     * Set the authentication information for the repository.
     *
     * @param string $repositoryName The unique name of repository
     * @param string $username       The username
     * @param string $password       The password
     */
    public function setAuthentication($repositoryName, $username, $password = null)
    {
        $this->authentications[$repositoryName] = ['username' => $username, 'password' => $password];
    }

    /**
     * Loads authentications from a config instance
     *
     * @param Config $config
     */
    public function loadConfiguration(Config $config)
    {
        $bitbucketOauth = $config->get('bitbucket-oauth') ?: [];
        $githubOauth    = $config->get('github-oauth') ?: [];
        $gitlabOauth    = $config->get('gitlab-oauth') ?: [];
        $gitlabToken    = $config->get('gitlab-token') ?: [];
        $httpBasic      = $config->get('http-basic') ?: [];
        $harmonyOauth   = $config->get('harmony-oauth') ?: [];

        // reload oauth tokens from config if available

        foreach ($bitbucketOauth as $domain => $cred) {
            $this->checkAndSetAuthentication($domain, $cred['consumer-key'], $cred['consumer-secret']);
        }

        foreach ($githubOauth as $domain => $token) {
            if (!preg_match('{^[.a-z0-9]+$}', $token)) {
                throw new \UnexpectedValueException('Your github oauth token for ' . $domain .
                    ' contains invalid characters: "' . $token . '"');
            }
            $this->checkAndSetAuthentication($domain, $token, 'x-oauth-basic');
        }

        foreach ($harmonyOauth as $domain => $token) {
            $this->checkAndSetAuthentication($domain, $token, 'oauth2');
        }

        foreach ($gitlabOauth as $domain => $token) {
            $this->checkAndSetAuthentication($domain, $token, 'oauth2');
        }

        foreach ($gitlabToken as $domain => $token) {
            $this->checkAndSetAuthentication($domain, $token, 'private-token');
        }

        // reload http basic credentials from config if available
        foreach ($httpBasic as $domain => $cred) {
            $this->checkAndSetAuthentication($domain, $cred['username'], $cred['password']);
        }

        // setup process timeout
        ProcessExecutor::setTimeout((int)$config->get('process-timeout'));
    }

    /**
     * Check for overwrite and set the authentication information for the repository.
     *
     * @param string $repositoryName The unique name of repository
     * @param string $username       The username
     * @param string $password       The password
     */
    protected function checkAndSetAuthentication($repositoryName, $username, $password = null)
    {
        if ($this->hasAuthentication($repositoryName)) {
            $auth = $this->getAuthentication($repositoryName);
            if ($auth['username'] === $username && $auth['password'] === $password) {
                return;
            }

            $this->error(sprintf("<warning>Warning: You should avoid overwriting already defined auth settings for %s.</warning>",
                $repositoryName));
        }
        $this->setAuthentication($repositoryName, $username, $password);
    }

    /**
     * Is this input means interactive?
     *
     * @return bool
     */
    public function isInteractive()
    {
        return $this->input->isInteractive();
    }

    /**
     * Is this output verbose?
     *
     * @return bool
     */
    public function isVerbose()
    {
        return $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
    }

    /**
     * Is the output very verbose?
     *
     * @return bool
     */
    public function isVeryVerbose()
    {
        return $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE;
    }

    /**
     * Is the output in debug verbosity?
     *
     * @return bool
     */
    public function isDebug()
    {
        return $this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG;
    }

    /**
     * Writes a message to the error output.
     *
     * @param string|array $messages  The message as an array of lines or a single string
     * @param bool         $newline   Whether to add a newline or not
     * @param int          $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function writeError($messages, $newline = true, $verbosity = IOInterface::NORMAL)
    {
        $this->doWrite($messages, $newline, true, $verbosity);
    }

    /**
     * Overwrites a previous message to the output.
     *
     * @param string|array $messages  The message as an array of lines or a single string
     * @param bool         $newline   Whether to add a newline or not
     * @param int          $size      The size of line
     * @param int          $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function overwrite($messages, $newline = true, $size = null, $verbosity = IOInterface::NORMAL)
    {
        $this->doOverwrite($messages, $newline, $size, false, $verbosity);
    }

    /**
     * Overwrites a previous message to the error output.
     *
     * @param string|array $messages  The message as an array of lines or a single string
     * @param bool         $newline   Whether to add a newline or not
     * @param int          $size      The size of line
     * @param int          $verbosity Verbosity level from the VERBOSITY_* constants
     */
    public function overwriteError($messages, $newline = true, $size = null, $verbosity = IOInterface::NORMAL)
    {
        $this->doOverwrite($messages, $newline, $size, true, $verbosity);
    }

    /**
     * Asks a confirmation to the user.
     * The question will be asked until the user answers by nothing, yes, or no.
     *
     * @param string $question The question to ask
     * @param bool   $default  The default answer if the user enters nothing
     *
     * @return bool true if the user has confirmed, false otherwise
     */
    public function askConfirmation($question, $default = true)
    {
        return $this->confirm($question, $default);
    }

    /**
     * Asks for a value and validates the response.
     * The validator receives the data to validate. It must return the
     * validated data when the data is valid and throw an exception
     * otherwise.
     *
     * @param string   $question  The question to ask
     * @param callable $validator A PHP callback
     * @param null|int $attempts  Max number of times to ask before giving up (default of null means infinite)
     * @param mixed    $default   The default answer if none is given by the user
     *
     * @throws \Exception When any of the validators return an error
     * @return mixed
     */
    public function askAndValidate($question, $validator, $attempts = null, $default = null)
    {
        /** @var QuestionHelper $helper */
        $helper   = $this->helperSet->get('question');
        $question = new Question($question, $default);
        $question->setValidator($validator);
        $question->setMaxAttempts($attempts);

        return $helper->ask($this->input, $this->getErrorOutput(), $question);
    }

    /**
     * Asks a question to the user and hide the answer.
     *
     * @param string $question The question to ask
     *
     * @return string The answer
     */
    public function askAndHideAnswer($question)
    {
        return $this->askHidden($question);
    }

    /**
     * Asks the user to select a value.
     *
     * @param string      $question     The question to ask
     * @param array       $choices      List of choices to pick from
     * @param bool|string $default      The default answer if the user enters nothing
     * @param bool|int    $attempts     Max number of times to ask before giving up (false by default, which means
     *                                  infinite)
     * @param string      $errorMessage Message which will be shown if invalid value from choice list would be picked
     * @param bool        $multiselect  Select more than one value separated by comma
     *
     * @throws \InvalidArgumentException
     * @return int|string|array          The selected value or values (the key of the choices array)
     */
    public function select($question, $choices, $default, $attempts = false, $errorMessage = 'Value "%s" is invalid',
                           $multiselect = false)
    {
        /** @var QuestionHelper $helper */
        $helper   = $this->helperSet->get('question');
        $question = new ChoiceQuestion($question, $choices, $default);
        $question->setMaxAttempts($attempts ?: null); // IOInterface requires false, and Question requires null or int
        $question->setErrorMessage($errorMessage);
        $question->setMultiselect($multiselect);

        $result = $helper->ask($this->input, $this->getErrorOutput(), $question);

        if (!is_array($result)) {
            return (string)array_search($result, $choices, true);
        }

        $results = [];
        foreach ($choices as $index => $choice) {
            if (in_array($choice, $result, true)) {
                $results[] = (string)$index;
            }
        }

        return $results;
    }

    /**
     * @param array|string $messages
     * @param bool         $newline
     * @param bool         $stderr
     * @param int          $verbosity
     */
    private function doWrite($messages, $newline, $stderr, $verbosity)
    {
        $sfVerbosity = $this->verbosityMap[$verbosity];
        if ($sfVerbosity > $this->output->getVerbosity()) {
            return;
        }

        // hack to keep our usage BC with symfony<2.8 versions
        // this removes the quiet output but there is no way around it
        // see https://github.com/composer/composer/pull/4913
        if (OutputInterface::VERBOSITY_QUIET === 0) {
            $sfVerbosity = OutputInterface::OUTPUT_NORMAL;
        }

        if (null !== $this->startTime) {
            $memoryUsage = memory_get_usage() / 1024 / 1024;
            $timeSpent   = microtime(true) - $this->startTime;
            $messages    = array_map(function ($message) use ($memoryUsage, $timeSpent) {
                return sprintf('[%.1fMB/%.2fs] %s', $memoryUsage, $timeSpent, $message);
            }, (array)$messages);
        }

        if (true === $stderr && $this->output instanceof ConsoleOutputInterface) {
            $this->output->getErrorOutput()->write($messages, $newline, $sfVerbosity);
            $this->lastMessageErr = implode($newline ? "\n" : '', (array)$messages);

            return;
        }

        $this->output->write($messages, $newline, $sfVerbosity);
        $this->lastMessage = implode($newline ? "\n" : '', (array)$messages);
    }

    /**
     * @param array|string $messages
     * @param bool         $newline
     * @param int|null     $size
     * @param bool         $stderr
     * @param int          $verbosity
     */
    private function doOverwrite($messages, $newline, $size, $stderr, $verbosity)
    {
        // messages can be an array, let's convert it to string anyway
        $messages = implode($newline ? "\n" : '', (array)$messages);

        // since overwrite is supposed to overwrite last message...
        if (!isset($size)) {
            // removing possible formatting of lastMessage with strip_tags
            $size = strlen(strip_tags($stderr ? $this->lastMessageErr : $this->lastMessage));
        }
        // ...let's fill its length with backspaces
        $this->doWrite(str_repeat("\x08", $size), false, $stderr, $verbosity);

        // write the new message
        $this->doWrite($messages, false, $stderr, $verbosity);

        // In cmd.exe on Win8.1 (possibly 10?), the line can not be cleared, so we need to
        // track the length of previous output and fill it with spaces to make sure the line is cleared.
        // See https://github.com/composer/composer/pull/5836 for more details
        $fill = $size - strlen(strip_tags($messages));
        if ($fill > 0) {
            // whitespace whatever has left
            $this->doWrite(str_repeat(' ', $fill), false, $stderr, $verbosity);
            // move the cursor back
            $this->doWrite(str_repeat("\x08", $fill), false, $stderr, $verbosity);
        }

        if ($newline) {
            $this->doWrite('', true, $stderr, $verbosity);
        }

        if ($stderr) {
            $this->lastMessageErr = $messages;
        } else {
            $this->lastMessage = $messages;
        }
    }
}