<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 *
 * Original work, part of the Magenx fork of mage-os/module-rma
 * (MIT, Copyright (c) Mage-OS Association) — see LICENSE.
 *
 * Magenx_RmaGraphQl — GraphQL coverage for Magenx_Rma.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Magenx_RmaGraphQl', __DIR__);
