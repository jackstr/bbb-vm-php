<?php declare(strict_types=1);
/**
 * /**
 *  BBB On Demand VM library for PHP
 *
 *  This allows customers to create and manage their own, dedicated virtual servers running BBB. So the '/bigbluebutton/api' end *  point is used
 *  to manage meetings using a standard BBB library or integration; the /vm endpoint is used to manage your own virtual machines - and you would
 *  then use a BBB library to interact with the actual BBB instance running on each machine.
 *
 * @author Richard Phillips
 */

namespace BBBondemand;

abstract class InstancesApiRoute {
    public const LIST = 'instances';
    public const CREATE = 'instances';
    public const GET = 'instances/{instanceID}';
    public const DELETE = 'instances/{instanceID}';
    public const START = 'instances/start';
    public const STOP = 'instances/stop';
    public const HISTORY = 'instances/{instanceID}/history';
}
