<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $kind
 * @property string $body
 * @property int $lock_version
 * @property int|null $updated_by_user_id
 */
#[Guarded(['*'])]
class PanelClaimMessageTemplate extends Model {}
