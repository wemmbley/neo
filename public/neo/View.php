<?php

declare(strict_types=1);

namespace App\Neo;

use App\Neo\Helpers\FileSystem\File;
use App\Neo\Helpers\FileSystem\Path;
use App\Neo\Helpers\Regex;
use Exception;

/**
 * Call View example
 *
 * <code>
 *      View::template('template')
 *          ->withParams([
 *              'var' => 'Title',
 *              'var2' => true,
 *          ])
 *          ->display();
 * </code>
 *
 *
 * Template example
 *
 * <code>
 *      @if($var === 'Title')
 *          <h2>Stay {{ $var2 }}</h2>
 *      @endif
 * </code>
 */
class View
{
    /**
     * Created to call View functions in foreach
     *
     * @var array|string[]
     */
    protected static array $compilers = [
        'Variables',
        'StructureOpenings',
        'StructureClosings',
        'Else',
        'Comments',
        'Csrf',
    ];

    protected static string $templatePath = '';
    protected static array $templateParams = [];

    /**
     * Select template to render.
     * Templates path: app/Views/
     *
     * @param string $template
     * @return static
     * @throws Exception
     */
    public static function template(string $template): static
    {
        static::$templatePath = Path::abs(sprintf('app/Views/%sView.php', $template));

        if ( ! File::exists(static::$templatePath))
            throw new Exception('View ' . static::$templatePath . ' not found');

        return new static;
    }

    /**
     * Render view with user's params.
     *
     * @param array $params
     * @return static
     */
    public static function withParams(array $params): static
    {
        static::$templateParams = $params;

        return new static;
    }

    /**
     * Render template from View compilers.
     * Display it after compiling.
     *
     * @return void
     * @throws Exception
     */
    public static function display(): void
    {
        $template = File::get(static::$templatePath);

        foreach (static::$compilers as $compiler)
        {
            $method = "compile{$compiler}";

            $template = static::$method($template);
        }

        static::renderContent($template);
    }

    /**
     * Extracting user params into template.
     *
     * @param string $template
     * @return void
     */
    protected static function renderContent(string $template): void
    {
        ob_start() and extract(static::$templateParams);

        try {
            eval('?>' . $template);
        } catch (Exception $exception) {
            ob_get_clean(); throw $exception;
        }

        echo ob_get_clean();
    }

    /**
     * Compile users variables
     *
     * <code>
     *      {{ $var }} to <?php echo $var ?>
     * </code>
     *
     * @param string $template
     * @return array|string
     * @throws Exception
     */
    protected static function compileVariables(string $template): array|string
    {
        return
            Regex::replaceAll('/\{\{(.+?)\}\}/')
                ->string($template)
                ->to('<?php echo $1; ?>');
    }

    /**
     * Compile csrf token
     *
     * @param string $template
     * @return array|string
     * @throws Exception
     */
    protected static function compileCsrf(string $template): array|string
    {
        return
            Regex::replaceAll('/@csrf/')
                ->string($template)
                ->to('<?php \App\Neo\Protector::csrf(); ?>');
    }

    /**
     * Compile structures opening
     *
     * <code>
     *      @if(1 === $b) to <?php if(1 === $b): ?>
     * </code>
     *
     * @param string $template
     * @return array|string
     * @throws Exception
     */
    protected static function compileStructureOpenings(string $template): array|string
    {
        return
            Regex::replace('/(\s*)@(if|elseif|foreach|for|while)(\s*\(.*\))/')
            ->string($template)
            ->to('$1<?php $2$3: ?>');
    }

    /**
     * Compile structure closing
     *
     * <code>
     *      @endif to <?php endif; ?>
     * </code>
     *
     * @param string $template
     * @return array|string
     * @throws Exception
     */
    protected static function compileStructureClosings(string $template): array|string
    {
        return
            Regex::replace('/(\s*)@(endif|endforeach|endfor|endwhile)(\s*)/')
                ->string($template)
                ->to('$1<?php $2; ?>$3');
    }

    /**
     * Compile else in if statement
     *
     * <code>
     *      @else to <?php else: ?>
     * </code>
     *
     * @param string $template
     * @return array|string
     * @throws Exception
     */
    protected static function compileElse(string $template): array|string
    {
        return
            Regex::replace('/(\s*)@(else)(\s*)/')
                ->string($template)
                ->to('$1<?php $2: ?>$3');
    }

    /**
     * Deleting View comments
     *
     * @param string $template
     * @return array|string
     * @throws Exception
     */
    protected static function compileComments(string $template): array|string
    {
        return
            Regex::replace('/\{\{--((.|\s)*?)--\}\}/')
                ->string($template)
                ->to('');
    }
}