<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Mcp;

use PrestaPro\PpMcp\Skill\SkillRepository;
use PrestaPro\PpMcp\Tool\ToolRegistry;
use PrestaPro\PpMcp\Tool\ToolRunner;

/**
 * Per-request service holder so meta tools (mcp_tool_search, mcp_skill_get…)
 * can reach the registry, skills and runner of the current request.
 */
class Runtime
{
    /** @var ToolRegistry|null */
    public static $registry;
    /** @var SkillRepository|null */
    public static $skills;
    /** @var ToolRunner|null */
    public static $runner;
}
