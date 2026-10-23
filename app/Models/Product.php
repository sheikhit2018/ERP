<?php
namespace App\Models;
class Product extends TenantModel {
    protected $table = 'products';
    protected $appends = ['label'];
    protected $casts = ['active' => 'boolean'];
    public function stock() { return $this->hasOne(FinishedGoodStock::class); }
    public function boms() { return $this->hasMany(Bom::class); }
    public function style() { return $this->belongsTo(ProductStyle::class, 'style_id'); }
    /** "Polo T-Shirt — White / M" */
    public function getLabelAttribute(): string {
        $v = array_filter([$this->attributes['color'] ?? null, $this->attributes['size'] ?? null]);
        return ($this->attributes['name'] ?? '').($v ? ' — '.implode(' / ', $v) : '');
    }
}
