<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Tool;

/**
 * Thrown when the API key scope, the key's shop restriction, the read-only
 * switch or the employee's back-office profile does not allow the call.
 */
class PermissionDeniedException extends ToolException
{
}
