<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallNatRule extends Model
{
    protected $fillable = [
        'router_id',
        'uuid',
        'external_id',
        'enabled',
        'priority',
        'chain',
        'action',
        'protocol',
        'src_address',
        'src_address_list',
        'dst_address',
        'src_port',
        'dst_port',
        'out_interface',
        'to_addresses',
        'to_ports',
        'comment',
        'log',
    ];

    protected $casts = [
        'enabled'  => 'boolean',
        'log'      => 'boolean',
        'priority' => 'integer',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'router_id');
    }

    public function toFrontend(): array
    {
        return [
            'id'             => $this->uuid,
            'kind'           => 'nat',
            'enabled'        => $this->enabled,
            'priority'       => $this->priority,
            'chain'          => $this->chain,
            'action'         => $this->action,
            'protocol'       => $this->protocol,
            'srcAddress'     => $this->src_address,
            'srcAddressList' => $this->src_address_list,
            'dstAddress'     => $this->dst_address,
            'srcPort'        => $this->src_port,
            'dstPort'        => $this->dst_port,
            'outInterface'   => $this->out_interface,
            'toAddresses'    => $this->to_addresses,
            'toPorts'        => $this->to_ports,
            'comment'        => $this->comment,
            'log'            => $this->log,
            'createdAt'      => $this->created_at ? (int) ($this->created_at->timestamp * 1000) : null,
            'updatedAt'      => $this->updated_at ? (int) ($this->updated_at->timestamp * 1000) : null,
        ];
    }
}
