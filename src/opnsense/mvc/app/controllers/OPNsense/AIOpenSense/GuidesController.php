<?php
namespace OPNsense\AIOpenSense;

/**
 * The menu uses /ui/aiopensense/guides/ (plural), but OPNsense maps the URL
 * segment directly to the controller class name. Keep the singular controller
 * for /ui/aiopensense/guide/view/ and expose this alias for the menu URL.
 */
class GuidesController extends GuideController
{
}
