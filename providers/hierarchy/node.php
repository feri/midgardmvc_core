<?php
interface midgardmvc_core_providers_hierarchy_node
{
    public function set_arguments(array $argv);

    public function get_object();

    public function get_configuration();

    public function get_component();

    public function get_arguments();

    public function get_path();

    public function set_path($path);

    public function get_child_nodes();

    public function get_child_by_name($name);

    public function has_child_nodes();

    public function get_parent_node();
}
