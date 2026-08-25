// Between a moment and a reading on a wall.
//
// The API speaks RFC 3339: a day, a time and the offset that makes the two mean
// one instant. A `datetime-local` control speaks the wall it is standing next
// to. Neither can do the other's job, and the browser is the only party that
// knows the offset — the server has no idea which room the page is open in — so
// the two conversions live here and both kits use them.

/** A moment as this browser's clock reads it: `2026-03-01T14:30:00`. */
export function localReading(moment) {
    if (!moment) return '';

    const at = new Date(moment);
    if (Number.isNaN(at.getTime())) return '';

    const pad = (n) => String(n).padStart(2, '0');

    return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}` +
        `T${pad(at.getHours())}:${pad(at.getMinutes())}:${pad(at.getSeconds())}`;
}

/**
 * A reading on this wall as the moment it names: `2026-03-01T14:30:00+01:00`.
 *
 * The offset is worked out for that day rather than for today, because an hour
 * in March and an hour in July are not the same distance from UTC wherever
 * summer time is kept.
 */
export function withOffset(reading) {
    if (!reading) return '';

    const at = new Date(reading);
    if (Number.isNaN(at.getTime())) return reading;

    const pad = (n) => String(n).padStart(2, '0');
    const seconds = reading.length === 16 ? `${reading}:00` : reading;
    const minutesEast = -at.getTimezoneOffset();
    const sign = minutesEast < 0 ? '-' : '+';
    const size = Math.abs(minutesEast);

    return `${seconds}${sign}${pad(Math.floor(size / 60))}:${pad(size % 60)}`;
}
