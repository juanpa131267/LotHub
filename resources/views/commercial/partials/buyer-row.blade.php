{{-- Fila de comprador del formulario de negociación. $row: index, client_id, label, pct. --}}
<tr>
    <td>
        <div class="picker" data-picker="{{ route('lookup.clients') }}">
            <input type="search" class="input" placeholder="Nombre, identificación o código" aria-label="Buscar comprador" style="margin-bottom:.35rem">
            <select name="buyers[{{ $row['index'] }}][client_id]" class="select @error('buyers.'.$row['index'].'.client_id') is-invalid @enderror" required
                    @if ($row['client_id']) data-selected='@json(['id' => $row['client_id'], 'label' => $row['label'] ?? 'Cliente #'.$row['client_id']])' @endif></select>
        </div>
        @error('buyers.'.$row['index'].'.client_id') <div class="error">{{ $message }}</div> @enderror
    </td>
    <td class="num">
        <input type="number" name="buyers[{{ $row['index'] }}][ownership_pct]" value="{{ $row['pct'] }}" min="0.01" max="100" step="0.01"
               class="input" style="max-width:110px;margin-left:auto" data-pct required>
    </td>
    <td>
        <label class="check"><input type="radio" name="primary" value="{{ $row['index'] }}" @checked((string) $primary === (string) $row['index'])> Titular</label>
    </td>
    <td class="actions"><button type="button" class="icon-btn danger" data-remove title="Quitar comprador" aria-label="Quitar comprador"><i class="ph ph-x"></i></button></td>
</tr>
