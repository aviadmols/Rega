<?php

namespace App\Modules\Widget\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A store team's decision about one page of the widget. See BuildPageBank::curated().
 *
 * @property string $id
 * @property string $shop_id
 * @property string $page_type product or content
 * @property string $page_external_id
 * @property string $candidate the section, such as complement
 * @property string $item_external_id the product or article inside it; '' for the section itself
 * @property string $action pin or hide
 * @property int|null $user_id
 */
class WidgetCuration extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const PIN = 'pin';

    public const HIDE = 'hide';

    /** Sections a product can be pinned into. */
    public const PRODUCT_SECTIONS = ['complement', 'family', 'alternatives', 'on_sale', 'article_products'];

    protected $guarded = ['id'];
}
