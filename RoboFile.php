<?php
use Symfony\Component\Finder\Finder;

class RoboFile extends \Robo\Tasks
{
    /**
     * Run the Robo unit tests.
     */
    public function test($args = "")
    {
        return $this->taskCodecept()
            ->args($args)
            ->run();
    }

    /**
     * Code sniffer.
     *
     * Run the PHP Codesniffer on a file or directory.
     *
     * @param string $file
     *    A file or directory to analyze.
     * @option $autofix Whether to run the automatic fixer or not.
     * @option $strict Show warnings as well as errors.
     *    Default is to show only errors.
     */
    public function sniff(
        $file = 'src/',
        $options = [
            'autofix' => false,
            'strict' => false,
        ]
    ) {
        $strict = $options['strict'] ? '' : '-n';
        $result = $this->taskExec("./vendor/bin/phpcs --standard=PSR2 {$strict} {$file}")->run();
        if (!$result->wasSuccessful()) {
            if (!$options['autofix']) {
                $options['autofix'] = $this->confirm('Would you like to run phpcbf to fix the reported errors?');
            }
            if ($options['autofix']) {
                $result = $this->taskExec("./vendor/bin/phpcbf --standard=PSR2 {$file}")->run();
            }
        }
        return $result;
    }

    /**
     * Release Robo.
     */
    public function release()
    {
        $this->yell("Releasing Robo");

        $releaseDescription = $this->ask("Description of Release\n");

        $this->docs();
        $this->taskGitStack()
            ->add('-A')
            ->commit("auto-update")
            ->pull()
            ->push()
            ->run();

        $this->pharPublish();
        $this->publish();

        $this->taskGitHubRelease(\Robo\Runner::VERSION)
            ->uri('Codegyre/Robo')
            ->description($releaseDescription)
            ->run();

        $this->versionBump();
    }

    /**
     * Update changelog.
     *
     * Add an entry to the Robo CHANGELOG.md file.
     *
     * @param string $addition The text to add to the change log.
     */
    public function changed($addition)
    {
        return $this->taskChangelog()
            ->version(\Robo\Runner::VERSION)
            ->change($addition)
            ->run();
    }

    /**
     * Update the version of Robo.
     *
     * @param string $version The new verison for Robo.
     *   Defaults to the next minor (bugfix) version after the current relelase.
     */
    public function versionBump($version = '')
    {
        if (empty($version)) {
            $versionParts = explode('.', \Robo\Runner::VERSION);
            $versionParts[count($versionParts)-1]++;
            $version = implode('.', $versionParts);
        }
        return $this->taskReplaceInFile(__DIR__.'/src/Runner.php')
            ->from("VERSION = '".\Robo\Runner::VERSION."'")
            ->to("VERSION = '".$version."'")
            ->run();
    }

    /**
     * Generate the Robo documentation files.
     */
    public function docs()
    {
        $collection = $this->collection();
        $collection->progressMessage('Generate documentation from source code.');
        $files = Finder::create()->files()->name('*.php')->in('src/Task');
        $docs = [];
        foreach ($files as $file) {
            if ($file->getFileName() == 'loadTasks.php') {
                continue;
            }
            if ($file->getFileName() == 'loadShortcuts.php') {
                continue;
            }
            $ns = $file->getRelativePath();
            if (!$ns) {
                continue;
            }
            $class = basename(substr($file, 0, -4));
            class_exists($class = "Robo\\Task\\$ns\\$class");
            $docs[$ns][] = $class;
        }
        ksort($docs);

        foreach ($docs as $ns => $tasks) {
            $taskGenerator = $this->taskGenDoc("docs/tasks/$ns.md");
            $taskGenerator->filterClasses(function (\ReflectionClass $r) {
                return !($r->isAbstract() || $r->isTrait()) && $r->implementsInterface('Robo\Contract\TaskInterface');
            })->prepend("# $ns Tasks");
            sort($tasks);
            foreach ($tasks as $class) {
                $taskGenerator->docClass($class);
            }

            $taskGenerator->filterMethods(
                function (\ReflectionMethod $m) {
                    if ($m->isConstructor() || $m->isDestructor() || $m->isStatic()) {
                        return false;
                    }
                    return !in_array($m->name, ['run', '', '__call', 'getCommand', 'getPrinted']) && $m->isPublic(); // methods are not documented
                }
            )->processClassSignature(
                function ($c) {
                    return "## " . preg_replace('~Task$~', '', $c->getShortName()) . "\n";
                }
            )->processClassDocBlock(
                function (\ReflectionClass $c, $doc) {
                    $doc = preg_replace('~@method .*?(.*?)\)~', '* `$1)` ', $doc);
                    $doc = str_replace('\\'.$c->name, '', $doc);
                    return $doc;
                }
            )->processMethodSignature(
                function (\ReflectionMethod $m, $text) {
                    return str_replace('#### *public* ', '* `', $text) . '`';
                }
            )->processMethodDocBlock(
                function (\ReflectionMethod $m, $text) {

                    return $text ? ' ' . trim(strtok($text, "\n"), "\n") : '';
                }
            )->addToCollection($collection);
        }
        $collection->progressMessage('Documentation generation complete.');
        return $collection->run();
    }

    /**
     * Publish Robo.
     *
     * Builds a site in gh-pages branch. Uses mkdocs
     */
    public function publish()
    {
        $current_branch = exec('git rev-parse --abbrev-ref HEAD');

        $collection = $this->collection();
        $this->taskGitStack()
            ->checkout('site')
            ->merge('master')
            ->addToCollection($collection);
        $this->taskGitStack()
            ->checkout($current_branch)
            ->addAsCompletion($collection);
        $this->taskFilesystemStack()
            ->copy('CHANGELOG.md', 'docs/changelog.md')
            ->addToCollection($collection);
        $this->taskFilesystemStack()
            ->remove('docs/changelog.md')
            ->addAsCompletion($collection);
        $this->taskExec('mkdocs gh-deploy')
            ->addToCollection($collection);
        return $collection->run();
    }

    /**
     * Build the Robo phar executable.
     */
    public function pharBuild()
    {
        $collection = $this->collection();

        $this->taskComposerInstall()
            ->noDev()
            ->printed(false)
            ->addToCollection($collection);

        $packer = $this->taskPackPhar('robo.phar');
        $files = Finder::create()->ignoreVCS(true)
            ->files()
            ->name('*.php')
            ->path('src')
            ->path('vendor')
            ->exclude('symfony/config/Tests')
            ->exclude('symfony/console/Tests')
            ->exclude('symfony/event-dispatcher/Tests')
            ->exclude('symfony/filesystem/Tests')
            ->exclude('symfony/finder/Tests')
            ->exclude('symfony/process/Tests')
            ->exclude('henrikbjorn/lurker/tests')
            ->in(__DIR__);
        foreach ($files as $file) {
            $packer->addFile($file->getRelativePathname(), $file->getRealPath());
        }
        $packer->addFile('robo', 'robo')
            ->executable('robo')
            ->addToCollection($collection);

        $this->taskComposerInstall()
            ->printed(false)
            ->addToCollection($collection);

        return $collection->run();
    }

    /**
     * Install Robo phar.
     *
     * Installs the Robo phar executable in /usr/bin. Uses 'sudo'.
     */
    public function pharInstall()
    {
        return $this->taskExec('sudo cp')
            ->arg('robo.phar')
            ->arg('/usr/bin/robo')
            ->run();
    }

    /**
     * Publish Robo phar.
     *
     * Commits the phar executable to Robo's GitHub pages site.
     */
    public function pharPublish()
    {
        $this->pharBuild();

        $this->_rename('robo.phar', 'robo-release.phar');
        $this->taskGitStack()->checkout('gh-pages')->run();
        $this->taskFilesystemStack()
            ->remove('robo.phar')
            ->rename('robo-release.phar', 'robo.phar')
            ->run();
        $this->taskGitStack()
            ->add('robo.phar')
            ->commit('robo.phar published')
            ->push('origin', 'gh-pages')
            ->checkout('master')
            ->run();
    }

    /**
     * Watch a file.
     *
     * Demonstrates the 'watch' command. Runs 'composer update' any time
     * composer.json changes.
     */
    public function tryWatch()
    {
        $this->taskWatch()->monitor(['composer.json', 'composer.lock'], function () {
            $this->taskComposerUpdate()->run();
        })->run();
    }

    /**
     * Demonstrates Robo input APIs.
     */
    public function tryInput()
    {
        $answer = $this->ask('how are you?');
        $this->say('You are '.$answer);
        $yes = $this->confirm('Do you want one more question?');
        if (!$yes) {
            return;
        }
        $lang = $this->askDefault('what is your favorite scripting language?', 'PHP');
        $this->say($lang);
        $pin = $this->askHidden('Ok, now tell your PIN code (it is hidden)');
        $this->yell('Ha-ha, your pin code is: '.$pin);
        $this->say('Bye!');
    }

    /**
     * Demonstrates parallel execution.
     *
     * @option $printed Print the output of each process.
     * @option $error Include an extra process that fails.
     */
    public function tryPara($options = ['printed' => false, 'error' => false])
    {
        $dir = __DIR__;
        $para = $this->taskParallelExec()
            ->printed($options['printed'])
            ->process("php $dir/tests/_data/parascript.php hey 4")
            ->process("php $dir/tests/_data/parascript.php hoy 3")
            ->process("php $dir/tests/_data/parascript.php gou 2")
            ->process("php $dir/tests/_data/parascript.php die 1");
        if ($options['error']) {
            $para->process("ls $dir/tests/_data/filenotfound");
        }
        return $para->run();
    }

    /**
     * Demonstrates Robo argument passing.
     *
     * @param $a The first parameter. Required.
     * @param $d The second parameter. Optional.
     */
    public function tryArgs($a, $b = 'default')
    {
        $this->say("The parameter a is $a and b is $b");
    }

    /**
     * Demonstrate Robo variable argument passing.
     *
     * @param $a A list of commandline parameters.
     */
    public function tryArrayArgs(array $a)
    {
        $this->say("The parameters passed are:\n" . var_export($a, true));
    }

    /**
     * Demonstrate Robo boolean options.
     *
     * @option $silent Supress output.
     */
    public function tryOptbool($opts = ['silent|s' => false])
    {
        if (!$opts['silent']) {
            $this->say("Hello, world");
        }
    }

    /**
     * Demonstrate the use of the PHP built-in webserver.
     */
    public function tryServer()
    {
        return $this->taskServer(8000)
            ->dir('site')
            ->arg('site/index.php')
            ->run();
    }

    /**
     * Demonstrate the use of the Robo open-browser task.
     */
    public function tryOpenBrowser()
    {
        return $this->taskOpenBrowser([
            'http://robo.li',
            'https://github.com/Codegyre/Robo'
            ])
            ->run();
    }

    /**
     * Demonstrate Robo error output and command failure.
     */
    public function tryError()
    {
        return $this->taskExec('ls xyzzy' . date('U'))->dir('/tmp')->run();
    }

    /**
     * Demonstrate Robo standard output and command success.
     */
    public function trySuccess()
    {
        return $this->taskExec('pwd')->run();
    }

    /**
     * Demonstrate deprecated task behavior.
     *
     * Demonstrate what happens when using a task that is created via
     * direct instantiation, which omits initialization done by the
     * container.  Emits a warning message.
     */
    public function tryDeprecated()
    {
        // Calling 'new' directly without manually setting
        // up dependencies will result in a deprecation warning.
        // @see RoboFile::trySuccess()
        return (new \Robo\Task\Base\Exec('pwd'))->run();
    }

    /**
     * Demonstrates Robo temporary directory usage.
     */
    public function tryTmpDir()
    {
        // Set up a collection to add tasks to
        $collection = $this->collection();

        // Get a temporary directory to work in. Note that we get a
        // name back, but the directory is not created until the task
        // runs.  This technically is not thread-safe, but we create
        // a random name, so it is unlikely to conflict.
        $tmpPath = $this->taskTmpDir()
            ->addToCollection($collection)
            ->getPath();

        $this->taskWriteToFile("$tmpPath/file.txt")
            ->line('Example file')
            ->addToCollection($collection);

        // We can create the temporary directory early by running
        // 'runWithoutCompletion()'.  n.b. if we called 'run()' at
        // this point, the collection's 'complete()' method would be
        // called, and the temporary directory would be deleted.
        $mktmpResult = $collection->runWithoutCompletion();
        if (!$mktmpResult->wasSuccessful()) {
            $this->say("Could not create temporary directory.");
            return 1;
        }

        if (is_dir($tmpPath)) {
            $this->say("Created a temporary directory at $tmpPath");
        } else {
            $this->say("Requested a temporary directory at $tmpPath, but it was not created");
        }

        // Run the task collection
        $result = $collection->run();

        if (is_dir($tmpPath)) {
            $this->say("The temporary directory at $tmpPath was not cleaned up after the collection completed.");
        } else {
            $this->say("The temporary directory at $tmpPath was automatically deleted.");
        }

        return $result;
    }

    /**
     * Generate a new Robo task that wraps an existing utility class.
     *
     * @param $className The name of the existing utility class to wrap.
     * @param $wrapperClassName The name of the wrapper class to create. Optional.
     * @usage generate:task 'Symfony\Component\Filesystem\Filesystem' FilesystemStack
     */
    public function generateTask($className, $wrapperClassName = "")
    {
        $delegate = new ReflectionClass($className);
        $replacements = [];

        $leadingCommentChars = " * ";
        $methodDescriptions = [];
        $methodImplementations = [];
        $immediateMethods = [];
        foreach ($delegate->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->name;
            $getter = preg_match('/^(get|has|is)/', $methodName);
            $setter = preg_match('/^(set|unset)/', $methodName);
            $argPrototypeList = [];
            $argNameList = [];
            $needsImplementation = false;
            foreach ($method->getParameters() as $arg) {
                $argDescription = '$' . $arg->name;
                $argNameList[] = $argDescription;
                if ($arg->isOptional()) {
                    $argDescription = $argDescription . ' = ' . str_replace("\n", "", var_export($arg->getDefaultValue(), true));
                    // We will create wrapper methods for any method that
                    // has default parameters.
                    $needsImplementation = true;
                }
                $argPrototypeList[] = $argDescription;
            }
            $argPrototypeString = implode(', ', $argPrototypeList);
            $argNameListString = implode(', ', $argNameList);

            if ($methodName[0] != '_') {
                $methodDescriptions[] = "@method $methodName($argPrototypeString)";

                if ($getter) {
                    $immediateMethods[] = "    public function $methodName($argPrototypeString)\n    {\n        return \$this->delegate->$methodName($argNameListString);\n    }";
                } elseif ($setter) {
                    $immediateMethods[] = "    public function $methodName($argPrototypeString)\n    {\n        \$this->delegate->$methodName($argNameListString);\n        return \$this;\n    }";
                } elseif ($needsImplementation) {
                    // Include an implementation for the wrapper method if necessary
                    $methodImplementations[] = "    protected function _$methodName($argPrototypeString)\n    {\n        \$this->delegate->$methodName($argNameListString);\n    }";
                }
            }
        }

        $classNameParts = explode('\\', $className);
        $delegate = array_pop($classNameParts);
        $delegateNamespace = implode('\\', $classNameParts);

        if (empty($wrapperClassName)) {
            $wrapperClassName = $delegate;
        }

        $replacements['{delegateNamespace}'] = $delegateNamespace;
        $replacements['{delegate}'] = $delegate;
        $replacements['{wrapperClassName}'] = $wrapperClassName;
        $replacements['{taskname}'] = "task$delegate";
        $replacements['{methodList}'] = $leadingCommentChars . implode("\n$leadingCommentChars", $methodDescriptions);
        $replacements['{immediateMethods}'] = "\n\n" . implode("\n\n", $immediateMethods);
        $replacements['{methodImplementations}'] = "\n\n" . implode("\n\n", $methodImplementations);

        $template = file_get_contents(__DIR__ . "/GeneratedWrapper.tmpl");
        $template = str_replace(array_keys($replacements), array_values($replacements), $template);

        print $template;
    }
}
