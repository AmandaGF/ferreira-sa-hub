/**
 * fix-webm-duration.js — escreve a duração no header EBML de um Blob WebM
 *
 * Contexto: MediaRecorder do Chrome/Edge grava WebM sem preencher o campo
 * Duration no segmento SegmentInformation do EBML. Resultado: WhatsApp
 * (e outros players) mostram 00:00 no áudio, induzindo o destinatário
 * a achar que está mudo.
 *
 * Uso:
 *   fixWebmDuration(blob, durationMs, function(fixedBlob) {
 *     enviarParaServidor(fixedBlob);
 *   });
 *
 * Baseado em fix-webm-duration por @yusitnikov (MIT), adaptado/simplificado.
 */
(function(global) {
    'use strict';

    function EbmlSection(source, offset, id, sizeLen, dataLen) {
        this.source = source;
        this.offset = offset;
        this.id = id;
        this.sizeLen = sizeLen;
        this.dataLen = dataLen;
        this.dataOffset = offset + id.length + sizeLen;
    }

    function readVint(data, off) {
        var b1 = data[off];
        if (b1 === 0) throw new Error('Invalid EBML: zero VINT');
        var len = 1;
        var mask = 0x80;
        while (!(b1 & mask)) { mask >>>= 1; len++; }
        if (len > 8) throw new Error('VINT too long');
        var value = b1 & (mask - 1);
        for (var i = 1; i < len; i++) {
            value = (value * 256) + data[off + i];
        }
        return { value: value, length: len };
    }

    function readElementId(data, off) {
        // ID mantém o bit de marker — diferente de size
        var b1 = data[off];
        if (b1 === 0) throw new Error('Invalid EBML ID');
        var len = 1;
        var mask = 0x80;
        while (!(b1 & mask)) { mask >>>= 1; len++; }
        if (len > 4) throw new Error('ID too long');
        var bytes = new Uint8Array(len);
        for (var i = 0; i < len; i++) bytes[i] = data[off + i];
        return { bytes: bytes, length: len };
    }

    function findElement(data, parentOffset, parentSize, targetIdHex) {
        var end = parentOffset + parentSize;
        var p = parentOffset;
        while (p < end) {
            var idInfo = readElementId(data, p);
            var sizeInfo = readVint(data, p + idInfo.length);
            var idHex = '';
            for (var i = 0; i < idInfo.bytes.length; i++) {
                var h = idInfo.bytes[i].toString(16);
                idHex += h.length < 2 ? '0' + h : h;
            }
            var dataStart = p + idInfo.length + sizeInfo.length;
            if (idHex === targetIdHex) {
                return { idOffset: p, idLen: idInfo.length, sizeLen: sizeInfo.length, dataOffset: dataStart, dataSize: sizeInfo.value };
            }
            p = dataStart + sizeInfo.value;
        }
        return null;
    }

    function writeFloat64(value) {
        var buf = new ArrayBuffer(8);
        new DataView(buf).setFloat64(0, value);
        return new Uint8Array(buf);
    }

    /**
     * Remonta o blob com Duration escrita dentro do Info do Segmento.
     * IDs relevantes:
     *   Segment       = 18538067
     *   SegmentInfo   = 1549A966
     *   Duration      = 4489
     */
    function rebuildWithDuration(buffer, durationMs) {
        var data = new Uint8Array(buffer);

        // 1. Localiza EBML header + Segment
        // EBML header ID = 1A45DFA3
        var ebmlIdInfo = readElementId(data, 0);
        var ebmlSize = readVint(data, ebmlIdInfo.length);
        var afterEbml = ebmlIdInfo.length + ebmlSize.length + ebmlSize.value;

        // Segment começa em afterEbml
        var segIdInfo = readElementId(data, afterEbml);
        var segSizeInfo = readVint(data, afterEbml + segIdInfo.length);
        var segDataStart = afterEbml + segIdInfo.length + segSizeInfo.length;
        // Tamanho do segmento — pode ser "unknown" (todos bits 1)
        var segSizeValue = segSizeInfo.value;
        var isUnknownSize = true;
        var maxVal = Math.pow(2, 7 * segSizeInfo.length) - 1;
        if (segSizeValue !== maxVal) isUnknownSize = false;

        // Limita busca pelo que temos no buffer
        var segContentSize = isUnknownSize ? (data.length - segDataStart) : segSizeValue;

        // 2. Encontra SegmentInfo (id 1549A966)
        var info = findElement(data, segDataStart, segContentSize, '1549a966');
        if (!info) return buffer; // nao encontrou, devolve original

        // 3. Dentro de SegmentInfo, procura Duration (id 4489)
        var duration = findElement(data, info.dataOffset, info.dataSize, '4489');

        // Valor em ms (float64 big-endian)
        var durationBytes = writeFloat64(durationMs);
        // Duration ID = 0x4489 (2 bytes), size = 0x88 (1 byte, valor 8)
        var newDurationElement = new Uint8Array([0x44, 0x89, 0x88, durationBytes[0], durationBytes[1], durationBytes[2], durationBytes[3], durationBytes[4], durationBytes[5], durationBytes[6], durationBytes[7]]);

        var result;
        if (duration) {
            // Substitui valor existente (8 bytes float64)
            var before = data.subarray(0, duration.dataOffset);
            var after = data.subarray(duration.dataOffset + duration.dataSize);
            // Aproveita que já tem Duration de mesmo tamanho → só sobrescreve os 8 bytes
            var fixed = new Uint8Array(data.length);
            fixed.set(before, 0);
            fixed.set(durationBytes, duration.dataOffset);
            fixed.set(after, duration.dataOffset + duration.dataSize);
            result = fixed.buffer;
        } else {
            // Injeta Duration como PRIMEIRO filho de SegmentInfo
            var newInfoSize = info.dataSize + newDurationElement.length;
            // Precisa re-escrever o size header do SegmentInfo
            var newSizeBytes = writeVintSize(newInfoSize);
            var oldSizeTotal = info.sizeLen;
            var delta = (newDurationElement.length) + (newSizeBytes.length - oldSizeTotal);
            var out = new Uint8Array(data.length + delta);
            var outOff = 0;
            // Copia tudo antes do size do SegmentInfo
            out.set(data.subarray(0, info.idOffset + info.idLen), outOff); outOff += info.idOffset + info.idLen;
            // Novo size
            out.set(newSizeBytes, outOff); outOff += newSizeBytes.length;
            // Duration injetada
            out.set(newDurationElement, outOff); outOff += newDurationElement.length;
            // Resto do SegmentInfo (antes estava em dataOffset..dataOffset+dataSize)
            out.set(data.subarray(info.dataOffset, info.dataOffset + info.dataSize), outOff); outOff += info.dataSize;
            // Resto do arquivo após SegmentInfo
            out.set(data.subarray(info.dataOffset + info.dataSize), outOff);
            // Ajusta tamanho do Segment (se não for unknown) — menos crítico, a maioria dos players lida
            result = out.buffer;
        }
        return result;
    }

    function writeVintSize(size) {
        // Escreve um VINT de 1-8 bytes pro size
        var len = 1;
        var maxFor = 127;  // 2^7 - 1
        while (size > maxFor && len < 8) { len++; maxFor = Math.pow(2, 7 * len) - 1; }
        var bytes = new Uint8Array(len);
        var marker = 1 << (8 - len);
        bytes[0] = marker | ((size >>> ((len - 1) * 8)) & 0xff);
        for (var i = 1; i < len; i++) {
            bytes[i] = (size >>> ((len - 1 - i) * 8)) & 0xff;
        }
        return bytes;
    }

    global.fixWebmDuration = function(blob, durationMs, callback) {
        if (!blob || typeof durationMs !== 'number' || durationMs <= 0) {
            callback(blob); return;
        }
        var reader = new FileReader();
        reader.onloadend = function() {
            try {
                var fixed = rebuildWithDuration(reader.result, durationMs);
                callback(new Blob([fixed], { type: blob.type }));
            } catch (e) {
                console.warn('[fixWebmDuration] falhou:', e);
                callback(blob); // fallback: envia original
            }
        };
        reader.onerror = function() { callback(blob); };
        reader.readAsArrayBuffer(blob);
    };

})(window);
