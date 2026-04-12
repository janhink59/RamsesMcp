<?php

abstract class McpRegistry {
    /**
     * Tuto metodu implementuješ ty. 
     * Musí vrátit pole nástrojů formátované podle MCP standardu (JSON Schema).
     * @return array
     */
    abstract public function getTools(): array;
}