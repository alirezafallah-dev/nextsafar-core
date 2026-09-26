<?php

namespace NextSafar\API;

abstract class BaseClient {
    protected $api_key;
    protected $timeout = 30;

    protected $last_response = null;
    protected $last_error = null;

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    public function get_last_response() {
        return $this->last_response;
    }

    public function get_last_error() {
        return $this->last_error;
    }

    abstract public function search_hotels($location, $options = []);
}