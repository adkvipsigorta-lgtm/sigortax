/**
 * Entity-aware name display formatters.
 *
 * KİŞİ ve ŞİRKET adları farklı semantic kurallarla kısaltılır.
 * Ellipsis ("...") KULLANILMAZ — ADK CRM standardı.
 *
 * COMPACT mode: table, list, narrow card, dashboard, mobile dense
 * FULL mode: detay sayfası, profil, modal geniş alan
 */

type DisplayMode = 'full' | 'compact'

/**
 * Whitespace normalization — baş/son boşluk ve çoklu boşluk temizleme.
 */
function normalize(name: string): string {
  return (name || '').trim().replace(/\s+/g, ' ')
}

/**
 * Şirket adı formatter.
 *
 * COMPACT: ilk 3 kelime (ellipsis yok)
 * FULL: tam isim
 *
 * Örnek:
 *   "ADK VİP SİGORTA ACENTELİK HİZMETLERİ" → "ADK VİP SİGORTA"
 *   "ABC OTOMOTİV SANAYİ VE TİCARET A.Ş."   → "ABC OTOMOTİV SANAYİ"
 */
export function formatCompanyName(name: string, mode: DisplayMode = 'full'): string {
  const clean = normalize(name)
  if (!clean) return ''
  if (mode === 'full') return clean

  const words = clean.split(' ')
  if (words.length <= 3) return clean
  return words.slice(0, 3).join(' ')
}

/**
 * Kişi adı formatter.
 *
 * COMPACT:
 *   1 kelime → aynen
 *   2 kelime → aynen (ad + soyad)
 *   3 kelime → aynen (ad + ikinci ad + soyad)
 *   4+ kelime → ilk 2 kelime + son kelime (soyadı)
 *
 * Örnek:
 *   "Aykut Okut"                    → "Aykut Okut"
 *   "Mehmet Ali Yılmaz"             → "Mehmet Ali Yılmaz"
 *   "Ahmet Mehmet Can Yılmaz"       → "Ahmet Mehmet Yılmaz"
 *   "Ahmet Mehmet Can Ali Yılmaz"   → "Ahmet Mehmet Yılmaz"
 *
 * FULL: tam isim
 */
export function formatPersonName(name: string, mode: DisplayMode = 'full'): string {
  const clean = normalize(name)
  if (!clean) return ''
  if (mode === 'full') return clean

  const words = clean.split(' ')
  if (words.length <= 3) return clean
  return `${words[0]} ${words[1]} ${words[words.length - 1]}`
}

/**
 * Entity-aware display name.
 * Customer type'a göre doğru formatter'ı seçer.
 *
 * @param name - Müşteri/şirket adı
 * @param type - 'INDIVIDUAL' | 'CORPORATE' (veya benzeri)
 * @param mode - 'full' | 'compact'
 */
/**
 * Entity-aware display name.
 * Customer type'a göre doğru formatter'ı seçer.
 */
export function formatDisplayName(
  name: string,
  type: 'INDIVIDUAL' | 'CORPORATE' | string,
  mode: DisplayMode = 'full'
): string {
  if (type === 'CORPORATE') return formatCompanyName(name, mode)
  return formatPersonName(name, mode)
}

/**
 * Kısa kişi adı — table column, temsilci adı gibi dar alanlar.
 * "Aykut Okut" → "Aykut O."
 * "Mehmet Ali Yılmaz" → "Mehmet Y."
 * "Aykut" → "Aykut"
 *
 * Not: Nokta (.) kullanır — bu ellipsis değil, initials formatıdır.
 */
export function shortName(fullName?: string): string {
  if (!fullName) return ''
  const parts = fullName.trim().split(/\s+/)
  if (parts.length === 1) {
    return parts[0].charAt(0).toLocaleUpperCase('tr') + parts[0].slice(1).toLocaleLowerCase('tr')
  }
  const first = parts[0].charAt(0).toLocaleUpperCase('tr') + parts[0].slice(1).toLocaleLowerCase('tr')
  const lastInitial = parts[parts.length - 1].charAt(0).toLocaleUpperCase('tr')
  return first + ' ' + lastInitial + '.'
}
