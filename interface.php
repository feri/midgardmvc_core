<?php
/**
 * @package midgardmvc_core
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Midgard MVC interface class
 *
 * @package midgardmvc_core
 */
class midgardmvc_core extends midgardmvc_core_component_baseclass
{
    /**
     * @var midgardmvc_core_services_configuration_yaml
     */
    public $configuration;

    /**
     * @var midgardmvc_core_component_loader
     */
    public $componentloader;

    /**
     * @var midgardmvc_core_helpers_context
     */
    public $context;
    
    /**
     * Access to installed FirePHP logger
     *
     * @var FirePHP
     */
    public $firephp = null;
    
    private static $instance = null;

    public function __construct()
    {
    }
    
    /**
     * Load all basic services needed for Midgard MVC usage. This includes configuration, authorization and the component loader.
     */
    public function load_base_services(array $local_configuration = null)
    {
        // Load the context helper and initialize first context
        $this->context = new midgardmvc_core_helpers_context();

        $this->configuration = new midgardmvc_core_services_configuration_yaml();
        $this->configuration->load_component('midgardmvc_core');
        if (!is_null($local_configuration))
        {
            $this->configuration->load_array($local_configuration);
        }

        if (    $this->configuration->development_mode
            and !class_exists('MFS\AppServer\DaemonicHandler') // firephp is not appserver-compatible
            and !class_exists('MFS_AppServer_DaemonicHandler') // + check for php52 version of class
           )
        {
            // Load FirePHP logger
            // TODO: separate setting
            include('FirePHPCore/FirePHP.class.php');
            if (class_exists('FirePHP'))
            {
                $this->firephp = FirePHP::getInstance(true);
            }
        }
    }
    
    /**
     * Helper for service initialization. Usually called via getters
     *
     * @param string $service Name of service to load
     */
    private function load_service($service)
    {
        if (isset($this->$service))
        {
            return;
        }
        
        $interface_file = MIDGARDMVC_ROOT . "/midgardmvc_core/services/{$service}.php";
        if (!file_exists($interface_file))
        {
            throw new InvalidArgumentException("Service {$service} not installed");
        }
        
        $service_implementation = $this->configuration->get("services_{$service}");
        if (!$service_implementation)
        {
            throw new Exception("No implementation defined for service {$service}");
        }

        if (strpos($service_implementation, '_') === false)
        {
            // Built-in service implementation called using the shorthand notation
            $service_implementation = "midgardmvc_core_services_{$service}_{$service_implementation}";
        }

        $this->$service = new $service_implementation();
    }

    /**
     * Helper for service initialization. Usually called via getters
     *
     * @param string $service Name of service to load
     */
    public function load_provider($provider)
    {
        if (isset($this->$provider))
        {
            return;
        }
        
        $interface_file = MIDGARDMVC_ROOT . "/midgardmvc_core/providers/{$provider}.php";
        if (!file_exists($interface_file))
        {
            throw new InvalidArgumentException("Provider {$provider} not installed");
        }
        
        $provider_implementation = $this->configuration->get("providers_{$provider}");
        if (!$provider_implementation)
        {
            throw new Exception("No implementation defined for provider {$provider}");
        }

        if (strpos($provider_implementation, '_') === false)
        {
            // Built-in provider implementation called using the shorthand notation
            $provider_implementation = "midgardmvc_core_providers_{$provider}_{$provider_implementation}";
        }

        $this->$provider = new $provider_implementation();
    }

    /**
     * Logging interface
     *
     * @param string $prefix Prefix to file the log under
     * @param string $message Message to be logged
     * @param string $loglevel Logging level, may be one of debug, info, message and warning
     */
    public function log($prefix, $message, $loglevel = 'debug')
    {
        if (!extension_loaded('midgard2'))
        {
            $this->log_with_helper($prefix, $message, $loglevel);
            return;
        }

        midgard_error::$loglevel("{$prefix}: {$message}");

        if (   $this->firephp
            && !$this->dispatcher->headers_sent())
        {
            $firephp_loglevel = $loglevel;
            // Handle mismatching loglevels
            switch ($loglevel)
            {
                case 'debug':
                case 'message':
                    $firephp_loglevel = 'log';
                    break;
                case 'warn':
                case 'warning':
                    $firephp_loglevel = 'warn';
                    break;
                case 'error':
                case 'critical':
                    $firephp_loglevel = 'error';
                    break;
            }
            $this->firephp->$firephp_loglevel("{$prefix}: {$message}");
        }
    }

    private function log_with_helper($prefix, $message, $loglevel)
    {
        // Temporary non-Midgard logger until midgard_error is backported to Ragnaroek
        static $logger = null;
        if (!$logger)
        {
            try
            {
                $logger = new midgardmvc_core_helpers_log();
            }
            catch (Exception $e)
            {
                // Unable to instantiate logger
                return;
            }
        }
        static $log_levels = array
        (
            'debug' => 4,
            'info' => 3,
            'message' => 2,
            'warn' => 1,
        );
        
        if ($log_levels[$loglevel] > $log_levels[$this->configuration->get('log_level')])
        {
            // Skip logging, too low level
            return;
        }
        $logger->log("{$prefix}: {$message}");
    }
    
    /**
     * Magic getter for service loading
     */
    public function __get($key)
    {
        $this->load_service($key);
        return $this->$key;
    }
    
    /**
     * Automatically load missing class files
     *
     * @param string $class_name Name of a missing PHP class
     */
    public static function autoload($class_name)
    {
        $class_parts = explode('_', $class_name);
        $component = '';
        foreach ($class_parts as $i => $part)
        {
            if ($component == '')
            {
                $component = $part;
            }
            else
            {
                $component .= "_{$part}";
            }
            unset($class_parts[$i]);
            if (is_dir(MIDGARDMVC_ROOT . "/{$component}"))
            {
                break;
            }
        }
 
        $path_under_component = implode('/', $class_parts);
        $path = MIDGARDMVC_ROOT . "/{$component}/{$path_under_component}.php";
        if (!file_exists($path))
        {
            return;
        }
        
        require($path);
    }
    
    /**
     * Process the current request, loading the node's component and dispatching the request to it
     */
    public function process()
    {
        // Load the head helper
        $this->head = new midgardmvc_core_helpers_head();

        try
        {
            $this->_process();
        }
        catch (Exception $e)
        {
            // ->serve() wouldn't be called — do cleanup here
            $this->_after_process();
            $this->cleanup_after_request();

            // rethrowing exception, if there is one
            throw $e;
        }

        $this->_after_process();
    }

    private function _process()
    {
        $this->context->create();
        
        $this->dispatcher->get_midgard_connection()->set_loglevel($this->configuration->get('log_level'));

        // Let dispatcher populate request with the node and other information used
        $request = $this->dispatcher->get_request();
        $request->populate_context();

        $this->log('Midgard MVC', "Serving " . $request->get_method() . " {$this->context->uri} at " . gmdate('r'), 'info');

        // Let injectors do their work
        $this->componentloader = new midgardmvc_core_component_loader();
        $this->componentloader->inject_process();

        // Show the world this is Midgard
        $this->head->add_meta
        (
            array
            (
                'name' => 'generator',
                'content' => "Midgard/" . mgd_version() . " MidgardMVC/{$this->componentloader->manifests['midgardmvc_core']['version']} PHP/" . phpversion()
            )
        );

        // Then initialize the component, so it also goes to template stack
        $this->dispatcher->initialize($request);
        try
        {
            $this->dispatcher->dispatch();
        }
        catch (midgardmvc_exception_unauthorized $exception)
        {
            // Pass the exception to authentication handler
            $this->authentication->handle_exception($exception);
        }

        $this->dispatcher->header('Content-Type: ' . $this->context->mimetype);
    }

    private function _after_process()
    {
        // add any cleanup after process() here
    }

    /**
     * Serve a request either through templating or the WebDAV server
     */
    public function serve()
    {
        try
        {
            $this->_serve();
        }
        catch (Exception $e)
        {
            // this will be executed even if _serve() had exception
            $this->_after_serve();
            throw $e;
        }

        $this->_after_serve();
    }

    private function _serve()
    {
        // Prepate the templates
        $this->templating->template();

        // Read contents from the output buffer and pass to Midgard MVC rendering
        $this->templating->display();
    }

    private function _after_serve()
    {
        // add any cleanup after serve() here
        $this->cleanup_after_request();
    }

    private function cleanup_after_request()
    {
        // commit session
        if ($this->dispatcher->session_is_started())
        {
            $this->dispatcher->session_commit();
        }

        // Clean up the context
        $this->context->delete();
    }

    /**
     * Access to the Midgard MVC instance
     */
    public static function get_instance($local_configuration = null)
    {
        if (!is_null(self::$instance))
        {
            return self::$instance;
        }

        if (   !is_null($local_configuration)
            && !is_array($local_configuration))
        {
            // Ratatoskr-style dispatcher selection fallback
            $local_configuration = array
            (
                'services_dispatcher' => $local_configuration,
            );
        }

        // Load and return MVC instance
        self::$instance = new midgardmvc_core();
        self::$instance->load_base_services($local_configuration);
        return self::$instance;
    }
}
?>
