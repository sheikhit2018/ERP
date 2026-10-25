<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** WooCommerce orders without SKUs: style + colour from the product name or a saved link, size from the checkout field. */
class StoreLinkTest extends TestCase {
    use RefreshDatabase;
    private string $base = 'http://erp.test';

    public function test_orders_without_sku_match_by_name_colour_and_checkout_size(): void {
        $this->withoutVite();
        TenantProvisioner::create('Sukran', 'erp.test', 'Owner', 'owner@example.com', 'secret-pass');
        $this->actingAs(User::where('email', 'owner@example.com')->firstOrFail());
        $q = []; foreach (['Off White', 'Black'] as $c) foreach (['L', 'XL', 'XXL'] as $z) $q[] = ['color' => $c, 'size' => $z, 'quantity' => 10];
        $this->post($this->base.'/products', ['code' => 'IPF', 'name' => 'Indian popcorn fabric', 'price_minor' => 99000, 'cost_minor' => 50000, 'colors' => ['Off White', 'Black'], 'sizes' => ['L', 'XL', 'XXL'], 'quantities' => $q])->assertSessionHasNoErrors();
        $this->post($this->base.'/products', ['code' => 'PLO', 'name' => 'Polo', 'price_minor' => 50000, 'cost_minor' => 20000, 'colors' => ['Navy'], 'sizes' => ['M', 'L'], 'quantities' => [['color' => 'Navy', 'size' => 'M', 'quantity' => 5]]]);
        $secret = $this->get($this->base.'/settings')->viewData('page')['props']['woo']['secret'];
        $this->post($this->base.'/settings', ['values' => ['store.woo_import_status' => 'orderconfirmed']]);
        $send = fn (array $b) => $this->call('POST', $this->base.'/api/hooks/woocommerce', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', json_encode($b), $secret, true))], json_encode($b));
        $order = fn (int $id, array $lines, string $size) => ['id' => $id, 'number' => (string) $id, 'status' => 'orderconfirmed', 'payment_method' => 'cod', 'total' => '1060', 'shipping_total' => '70', 'discount_total' => '0',
            'billing' => ['first_name' => 'Jahirul', 'phone' => '01300000000', 'address_1' => 'test order', 'city' => 'Dhaka'], 'shipping' => ['first_name' => 'Jahirul', 'address_1' => 'test order'],
            'meta_data' => [['id' => 1, 'key' => 'billing_size', 'value' => $size], ['id' => 2, 'key' => 'is_vat_exempt', 'value' => 'no']], 'line_items' => $lines];
        $variant = fn ($c, $z) => (int) DB::table('products')->where('color', $c)->where('size', $z)->value('id');

        // 1) Name contains style + colour, size from the checkout field -> auto-linked and imported.
        $send($order(55437, [['product_id' => 900, 'variation_id' => 0, 'name' => 'Indian popcorn fabric Off White', 'sku' => '', 'quantity' => 1, 'price' => 990, 'meta_data' => []]], 'XXL'))
            ->assertOk()->assertJsonPath('status', 'imported');
        $oid = (int) DB::table('sales_orders')->max('id');
        $this->assertSame([$variant('Off White', 'XXL'), 1], [(int) DB::table('sales_order_items')->where('sales_order_id', $oid)->value('product_id'), (int) DB::table('sales_order_items')->where('sales_order_id', $oid)->value('quantity')]);
        $this->assertSame(9, (int) DB::table('finished_good_stocks')->where('product_id', $variant('Off White', 'XXL'))->value('available_stock'));
        $this->assertTrue((bool) DB::table('store_product_links')->where('external_id', '900')->value('auto'));
        // Same website product later, size written "2XL" -> uses the saved link.
        $send($order(55438, [['product_id' => 900, 'name' => 'Popcorn Off White (old name)', 'sku' => '', 'quantity' => 2, 'price' => 990]], '2XL'))->assertJsonPath('status', 'imported');
        // 2) Unknown name -> waits; owner links it once -> waiting order imported; size from variation attribute.
        $send($order(55439, [['product_id' => 901, 'variation_id' => 9011, 'name' => 'Premium shirt', 'sku' => '', 'quantity' => 1, 'price' => 990,
            'meta_data' => [['key' => 'pa_size', 'value' => 'xl', 'display_value' => 'XL']]]], ''))->assertJsonPath('status', 'failed');
        $p = $this->get($this->base.'/orders/import')->viewData('page')['props'];
        $link = collect($p['links'])->firstWhere('external_id', '9011');
        $this->assertFalse($link['linked']);
        $style = collect($p['styles'])->firstWhere('code', 'IPF');
        $this->post($this->base."/orders/import/links/{$link['id']}", ['product_style_id' => $style['id']])->assertSessionHasErrors('inventory'); // colour needed
        $this->post($this->base."/orders/import/links/{$link['id']}", ['product_style_id' => $style['id'], 'color' => 'Black'])->assertSessionHas('success');
        $this->assertSame('imported', DB::table('incoming_orders')->where('external_id', '55439')->value('status'));
        $this->assertSame(9, (int) DB::table('finished_good_stocks')->where('product_id', $variant('Black', 'XL'))->value('available_stock'));
        // 3) Size missing on the order -> clear message, nothing reserved.
        $send($order(55440, [['product_id' => 900, 'name' => 'Indian popcorn fabric Off White', 'sku' => '', 'quantity' => 1, 'price' => 990]], ''))->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('Size not found', DB::table('incoming_orders')->where('external_id', '55440')->value('error'));
        // 4) Single-colour style recognised without colour in the name.
        $send($order(55441, [['product_id' => 902, 'name' => 'Polo T-shirt', 'sku' => '', 'quantity' => 1, 'price' => 500]], 'M'))->assertJsonPath('status', 'imported');
    }
}
