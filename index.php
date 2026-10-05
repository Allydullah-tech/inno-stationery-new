<?php
/**
 * Root redirect — put this file directly in your hosting's public
 * folder (e.g. InfinityFree's "htdocs"), as a SIBLING of your
 * frontend/, backend/, and database/ folders.
 *
 * This is now an internal staff-only system, so the root domain
 * goes straight to the login page instead of a public storefront.
 */
header('Location: frontend/index.html');
exit;
