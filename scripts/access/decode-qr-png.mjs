#!/usr/bin/env node
import fs from 'node:fs'
import zlib from 'node:zlib'
import { createRequire } from 'node:module'
const require = createRequire(import.meta.url)
let jsQR
try {
  jsQR = require('../../frontend/node_modules/jsqr/dist/jsQR.js')
} catch {
  jsQR = require('jsqr')
}

const file = process.argv[2]
let expected = process.argv[3] || ''
if (expected.startsWith('@')) {
  expected = fs.readFileSync(expected.slice(1), 'utf8').trim()
}

if (!file) {
  console.error('Usage: node scripts/access/decode-qr-png.mjs <qr.png> [expected-value]')
  process.exit(2)
}

const buffer = fs.readFileSync(file)

function readUInt32(offset) {
  return buffer.readUInt32BE(offset)
}

function parsePng(png) {
  if (png.toString('hex', 0, 8) !== '89504e470d0a1a0a') {
    throw new Error('Input is not a PNG file')
  }

  let offset = 8
  let width = 0
  let height = 0
  let colorType = 0
  let bitDepth = 0
  let palette = []
  const idat = []

  while (offset < png.length) {
    const length = png.readUInt32BE(offset)
    const type = png.toString('ascii', offset + 4, offset + 8)
    const dataStart = offset + 8
    const dataEnd = dataStart + length
    const data = png.subarray(dataStart, dataEnd)

    if (type === 'IHDR') {
      width = data.readUInt32BE(0)
      height = data.readUInt32BE(4)
      bitDepth = data[8]
      colorType = data[9]
    } else if (type === 'PLTE') {
      palette = []
      for (let i = 0; i < data.length; i += 3) {
        palette.push([data[i], data[i + 1], data[i + 2]])
      }
    } else if (type === 'IDAT') {
      idat.push(data)
    } else if (type === 'IEND') {
      break
    }

    offset = dataEnd + 4
  }

  if (!((bitDepth === 8 && colorType === 2) || (colorType === 3 && [1, 2, 4, 8].includes(bitDepth)))) {
    throw new Error(`Unsupported PNG format: bitDepth=${bitDepth}, colorType=${colorType}`)
  }
  if (colorType === 3 && palette.length === 0) {
    throw new Error('Indexed PNG has no palette')
  }

  const inflated = zlib.inflateSync(Buffer.concat(idat))
  const stride = colorType === 2 ? width * 3 : Math.ceil(width * bitDepth / 8)
  const rgba = new Uint8ClampedArray(width * height * 4)
  let src = 0
  let prev = Buffer.alloc(stride)

  for (let y = 0; y < height; y += 1) {
    const filter = inflated[src]
    src += 1
    const row = Buffer.from(inflated.subarray(src, src + stride))
    src += stride

    const bpp = colorType === 2 ? 3 : 1
    for (let x = 0; x < stride; x += 1) {
      const left = x >= bpp ? row[x - bpp] : 0
      const up = prev[x]
      const upLeft = x >= bpp ? prev[x - bpp] : 0
      if (filter === 1) row[x] = (row[x] + left) & 0xff
      else if (filter === 2) row[x] = (row[x] + up) & 0xff
      else if (filter === 3) row[x] = (row[x] + Math.floor((left + up) / 2)) & 0xff
      else if (filter === 4) {
        const p = left + up - upLeft
        const pa = Math.abs(p - left)
        const pb = Math.abs(p - up)
        const pc = Math.abs(p - upLeft)
        const predictor = pa <= pb && pa <= pc ? left : (pb <= pc ? up : upLeft)
        row[x] = (row[x] + predictor) & 0xff
      } else if (filter !== 0) {
        throw new Error(`Unsupported PNG filter ${filter}`)
      }
    }

    for (let x = 0; x < width; x += 1) {
      const di = (y * width + x) * 4
      if (colorType === 2) {
        const si = x * 3
        rgba[di] = row[si]
        rgba[di + 1] = row[si + 1]
        rgba[di + 2] = row[si + 2]
      } else {
        const bitOffset = x * bitDepth
        const byte = row[Math.floor(bitOffset / 8)]
        const shift = 8 - bitDepth - (bitOffset % 8)
        const index = (byte >> shift) & ((1 << bitDepth) - 1)
        const [r, g, b] = palette[index] || [0, 0, 0]
        rgba[di] = r
        rgba[di + 1] = g
        rgba[di + 2] = b
      }
      rgba[di + 3] = 255
    }

    prev = row
  }

  return { width, height, data: rgba }
}

const image = parsePng(buffer)
const decoded = jsQR(image.data, image.width, image.height)
const value = decoded?.data || ''
const result = {
  decoded: Boolean(decoded),
  width: image.width,
  height: image.height,
  decodedLength: value.length,
  matchesExpected: expected ? value === expected : undefined,
}

console.log(JSON.stringify(result, null, 2))

if (!decoded || (expected && value !== expected)) {
  process.exit(1)
}
